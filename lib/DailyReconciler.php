<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ServerConfig;
use WHMCS\Database\Capsule;

final class DailyReconciler
{
    public function __construct(
        private int $serverId,
        private array $serverParams,
    ) {
    }

    public function run(): void
    {
        $cfg = ServerConfig::fromParams($this->serverParams);
        if (!$cfg->reconcileDaily()) {
            return;
        }
        $client = new Client($cfg);

        // Find services on this server that are still active.
        $services = Capsule::table('tblhosting')
            ->where('domainstatus', 'Active')
            ->where('server', $this->serverId)
            ->get();

        foreach ($services as $svc) {
            $customFields = $this->loadCustomFields((int)$svc->id);
            $userId = (int)($customFields['continuum_user_id'] ?? 0);
            if ($userId === 0) {
                logActivity("continuum reconcile: service {$svc->id} has no continuum_user_id; skipping");
                continue;
            }
            try {
                $user = $client->getUser($userId);
            } catch (ContinuumApiException $e) {
                logActivity("continuum reconcile: service {$svc->id} → user {$userId}: " . $e->getMessage());
                continue;
            }
            $expected = [
                'enabled' => strtolower((string)$svc->domainstatus) === 'active',
                // (role/library_ids would require reading ProductConfig; left for follow-up)
            ];
            foreach (DriftCheck::compare((int)$svc->id, $userId, $expected, $user) as $msg) {
                logActivity("continuum reconcile drift: {$msg}");
            }
        }
    }

    /** @return array<string, string> */
    private function loadCustomFields(int $serviceId): array
    {
        $rows = Capsule::table('tblcustomfields')
            ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->where('tblcustomfields.type', 'product')
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->fieldname] = $row->value;
        }
        return $out;
    }
}
