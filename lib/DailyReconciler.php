<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\Silo\ClientInterface;
use WHMCS\Database\Capsule;

/**
 * Daily drift check: for every active service on this Silo server,
 * read the WHMCS-side expected state (product config + configurable
 * options + service status) and compare to Silo's observed user
 * record. Reports drift to the WHMCS activity log; does NOT auto-fix.
 * An admin clicks "Reconcile from WHMCS" on the drifted service to
 * push the expected state.
 *
 * Comparison covers every attribute AttributeMapper produces (limits,
 * library_ids, downloads, playback quality, role) plus the enabled
 * flag — not just enabled.
 */
final class DailyReconciler
{
    public function __construct(
        private int $serverId,
        private array $serverParams,
        private ?ClientInterface $client = null,
    ) {
    }

    public function run(): void
    {
        $cfg = ServerConfig::fromParams($this->serverParams);
        $client = $this->client ?? new Client($cfg);
        $mapper = new AttributeMapper();

        $services = Capsule::table('tblhosting')
            ->where('domainstatus', 'Active')
            ->where('server', $this->serverId)
            ->get();

        foreach ($services as $svc) {
            $serviceId = (int)$svc->id;
            $userId = $this->linkedUserId($serviceId);
            if ($userId === 0) {
                logActivity("silo reconcile: service {$serviceId} has no silo_user_id; skipping");
                continue;
            }

            $pcParams = $this->productConfigParams((int)($svc->packageid ?? 0));
            try {
                $pc = ProductConfig::fromParams($pcParams);
            } catch (\InvalidArgumentException $e) {
                logActivity(
                    "silo reconcile: service {$serviceId} product "
                    . (int)($svc->packageid ?? 0) . " has invalid config: " . $e->getMessage()
                );
                continue;
            }

            $svcOptions = $this->serviceConfigurableOptions($serviceId);
            $expected = array_merge(
                $mapper->apply($pc, $svcOptions),
                ['enabled' => strtolower((string)$svc->domainstatus) === 'active'],
            );

            try {
                $user = $client->getUser($userId);
            } catch (SiloApiException $e) {
                logActivity("silo reconcile: service {$serviceId} -> user {$userId}: " . $e->getMessage());
                continue;
            }

            foreach (DriftCheck::compare($serviceId, $userId, $expected, $user) as $msg) {
                logActivity("silo reconcile drift: {$msg}");
            }
        }
    }

    /**
     * Resolve the Silo user ID for a WHMCS service via its custom
     * fields. Two simple equality queries (the join lives in the real
     * Capsule but the test shim doesn't model joins; reads are cheap).
     */
    private function linkedUserId(int $serviceId): int
    {
        try {
            $fieldId = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', 'silo_user_id')
                ->value('id');
            if ($fieldId === null) {
                return 0;
            }
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int)$fieldId)
                ->where('relid', $serviceId)
                ->value('value');
        } catch (\Throwable $e) {
            return 0;
        }
        $raw = trim((string)($value ?? ''));
        return ($raw !== '' && ctype_digit($raw)) ? (int)$raw : 0;
    }

    /**
     * Re-assemble the `configoption1..N` keys ProductConfig::fromParams
     * expects, sourced directly from the linked product row.
     *
     * @return array<string, string>
     */
    private function productConfigParams(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        try {
            $product = Capsule::table('tblproducts')->where('id', $productId)->first();
        } catch (\Throwable $e) {
            return [];
        }
        if ($product === null) {
            return [];
        }
        $out = [];
        // 24 is WHMCS's per-product configoption ceiling; the module
        // uses 1..11 today. Iterating to 24 is harmless and future-proof.
        for ($i = 1; $i <= 24; $i++) {
            $key = "configoption{$i}";
            $out[$key] = (string)($product->$key ?? '');
        }
        return $out;
    }

    /**
     * Re-build the per-service configurable-options selection in the
     * `[{name, value}]` shape AttributeMapper expects. Quantity-type
     * options surface their integer; dropdown/radio/yes-no options
     * surface the selected sub-option's name.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function serviceConfigurableOptions(int $serviceId): array
    {
        try {
            $picks = Capsule::table('tblhostingconfigoptions')
                ->where('relid', $serviceId)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($picks as $p) {
            try {
                $option = Capsule::table('tblproductconfigoptions')
                    ->where('id', (int)($p->configid ?? 0))->first();
            } catch (\Throwable $e) {
                continue;
            }
            if ($option === null) {
                continue;
            }
            $name = (string)($option->optionname ?? '');
            if ($name === '') {
                continue;
            }
            // optiontype 4 = quantity; the customer-entered number lives
            // in tblhostingconfigoptions.qty and IS the value.
            if ((int)($option->optiontype ?? 0) === 4) {
                $value = (string)($p->qty ?? 0);
            } else {
                try {
                    $value = (string)Capsule::table('tblproductconfigoptionssub')
                        ->where('id', (int)($p->optionid ?? 0))
                        ->value('optionname');
                } catch (\Throwable $e) {
                    continue;
                }
            }
            $out[] = ['name' => $name, 'value' => $value];
        }
        return $out;
    }
}
