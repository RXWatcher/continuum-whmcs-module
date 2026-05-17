<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Creates the product-scoped service custom fields the module needs
 * (continuum_user_id, continuum_library_names_cache, and optionally
 * desired_username) directly in tblcustomfields when an admin hasn't
 * added them by hand. Inserting into tblcustomfields is exactly what the
 * WHMCS admin "Custom Fields" tab does, so this just removes the manual
 * setup step. Idempotent: existing fields are left untouched.
 *
 * Best-effort and non-fatal — if WHMCS's Capsule isn't available (e.g.
 * unit tests) or the insert fails, callers fall back to the existing
 * "custom field is not declared" guidance.
 */
final class CustomFieldProvisioner
{
    /**
     * @param array<int, array{name: string, adminonly: bool, showorder: bool, description?: string}> $fields
     */
    public function ensure(int $serviceId, int $productId, array $fields): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }
        if ($productId <= 0) {
            $productId = $this->resolveProductId($serviceId);
        }
        if ($productId <= 0) {
            return;
        }

        foreach ($fields as $f) {
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $productId)
                ->where('fieldname', $f['name'])
                ->exists();
            if ($exists) {
                continue;
            }
            $now = date('Y-m-d H:i:s');
            Capsule::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => $f['name'],
                'fieldtype' => 'text',
                'description' => $f['description'] ?? '',
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => $f['adminonly'] ? 'on' : '',
                'required' => '',
                'showorder' => $f['showorder'] ? 'on' : '',
                'showinvoice' => '',
                'sortorder' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveProductId(int $serviceId): int
    {
        if ($serviceId <= 0) {
            return 0;
        }
        try {
            return (int)Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->value('packageid');
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
