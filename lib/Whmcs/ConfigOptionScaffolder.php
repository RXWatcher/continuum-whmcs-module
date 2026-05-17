<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Whmcs;

use WHMCS\Database\Capsule;

/**
 * One-shot, idempotent scaffolding of a "Continuum Options" configurable
 * option group whose option names match what AttributeMapper recognises.
 *
 * Creates the group, its options/sub-options, 0.00 pricing rows for every
 * currency (so the options are valid and visible — free until an admin
 * sets real prices), and links the group to every continuum product.
 * Anything that already exists (matched by name) is left untouched, so
 * re-running never duplicates or overwrites admin-curated pricing.
 *
 * WHMCS optiontype: 1=dropdown, 2=radio, 3=yes/no, 4=quantity.
 */
final class ConfigOptionScaffolder
{
    private const GROUP_NAME = 'Continuum Options';

    /**
     * @param array<int, array{id:int, name:string}> $libraries live Continuum libraries (may be empty)
     * @return array{group:string, created:string[], skipped:string[]}
     */
    public function scaffold(array $libraries): array
    {
        $created = [];
        $skipped = [];

        $gid = (int)(Capsule::table('tblproductconfiggroups')
            ->where('name', self::GROUP_NAME)->value('id') ?? 0);
        if ($gid === 0) {
            $gid = (int)Capsule::table('tblproductconfiggroups')->insertGetId([
                'name' => self::GROUP_NAME,
                'description' => 'Auto-scaffolded by the continuum module. Set prices, then it is ready.',
            ]);
            $created[] = "group '" . self::GROUP_NAME . "'";
        } else {
            $skipped[] = "group '" . self::GROUP_NAME . "' (exists)";
        }

        $order = 0;
        foreach ($this->optionSpecs($libraries) as $spec) {
            $order++;
            [$label, $changed] = $this->ensureOption($gid, $spec, $order);
            if ($changed) {
                $created[] = $label;
            } else {
                $skipped[] = $label;
            }
        }

        // For every continuum product: link the group and ensure the
        // internal custom fields exist (so a product with no provisioned
        // service yet is fully prepped from this one action). All fields
        // are created admin-only / not on the order form; an admin
        // manually enables Show on Order Form for desired_username.
        $productIds = Capsule::table('tblproducts')
            ->where('servertype', 'continuum')->pluck('id');
        $cf = new CustomFieldProvisioner();
        foreach ($productIds as $pid) {
            $cf->ensure(0, (int)$pid, CustomFieldProvisioner::moduleFields());

            $linked = Capsule::table('tblproductconfiglinks')
                ->where('gid', $gid)->where('pid', $pid)->exists();
            if ($linked) {
                $skipped[] = "link -> product {$pid} (exists)";
                continue;
            }
            Capsule::table('tblproductconfiglinks')->insert(['gid' => $gid, 'pid' => $pid]);
            $created[] = "link -> product {$pid}";
        }
        if (count($productIds) > 0) {
            $created[] = 'custom fields ensured on ' . count($productIds) . ' product(s)';
        }

        return ['group' => self::GROUP_NAME, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param array{name:string, type:int, subs:string[], qtymin?:int, qtymax?:int} $spec
     * @return array{0:string, 1:bool} [label, created?]
     */
    private function ensureOption(int $gid, array $spec, int $order): array
    {
        $existingId = (int)(Capsule::table('tblproductconfigoptions')
            ->where('gid', $gid)->where('optionname', $spec['name'])->value('id') ?? 0);
        if ($existingId !== 0) {
            return ["option '{$spec['name']}' (exists)", false];
        }

        $optionId = (int)Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $gid,
            'optionname' => $spec['name'],
            'optiontype' => (string)$spec['type'],
            'qtyminimum' => $spec['qtymin'] ?? 0,
            'qtymaximum' => $spec['qtymax'] ?? 0,
            'order' => $order,
            'hidden' => 0,
        ]);

        foreach ($spec['subs'] as $i => $subName) {
            $subId = (int)Capsule::table('tblproductconfigoptionssub')->insertGetId([
                'configid' => $optionId,
                'optionname' => $subName,
                'sortorder' => $i,
                'hidden' => 0,
            ]);
            $this->ensureZeroPricing($subId);
        }

        return ["option '{$spec['name']}'", true];
    }

    private function ensureZeroPricing(int $subId): void
    {
        foreach (Capsule::table('tblcurrencies')->pluck('id') as $currencyId) {
            $exists = Capsule::table('tblpricing')
                ->where('type', 'configoptions')
                ->where('currency', $currencyId)
                ->where('relid', $subId)->exists();
            if ($exists) {
                continue;
            }
            Capsule::table('tblpricing')->insert([
                'type' => 'configoptions',
                'currency' => $currencyId,
                'relid' => $subId,
                'msetupfee' => 0, 'qsetupfee' => 0, 'ssetupfee' => 0,
                'asetupfee' => 0, 'bsetupfee' => 0, 'tsetupfee' => 0,
                'monthly' => 0, 'quarterly' => 0, 'semiannually' => 0,
                'annually' => 0, 'biennially' => 0, 'triennially' => 0,
            ]);
        }
    }

    /**
     * Names here must normalise to what AttributeMapper recognises.
     *
     * @param array<int, array{id:int, name:string}> $libraries
     * @return array<int, array{name:string, type:int, subs:string[], qtymin?:int, qtymax?:int}>
     */
    private function optionSpecs(array $libraries): array
    {
        $specs = [
            ['name' => 'Extra Streams', 'type' => 4, 'subs' => ['Extra Streams'], 'qtymin' => 0, 'qtymax' => 20],
            ['name' => 'Extra Transcodes', 'type' => 4, 'subs' => ['Extra Transcodes'], 'qtymin' => 0, 'qtymax' => 10],
            ['name' => 'Extra Profiles', 'type' => 4, 'subs' => ['Extra Profiles'], 'qtymin' => 0, 'qtymax' => 10],
            ['name' => '4K Streaming', 'type' => 3, 'subs' => ['Yes']],
            ['name' => 'Downloads Allowed', 'type' => 3, 'subs' => ['Yes']],
            ['name' => 'Download Transcode Allowed', 'type' => 3, 'subs' => ['Yes']],
            ['name' => 'Max Playback Quality', 'type' => 1, 'subs' => ['Unrestricted', '1080p', '4k']],
        ];

        // Per-library opt-in checkboxes. AttributeMapper matches the
        // option name against /^library (id )?N$/, so the name must be
        // exactly "Library {id}" (the human name can't be in it).
        foreach ($libraries as $lib) {
            if (($lib['id'] ?? 0) > 0) {
                $specs[] = ['name' => 'Library ' . (int)$lib['id'], 'type' => 3, 'subs' => ['Yes']];
            }
        }

        return $specs;
    }
}
