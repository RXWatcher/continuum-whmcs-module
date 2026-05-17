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
     * The internal custom fields the module relies on. All admin-only
     * and never on the order form — including desired_username, which is
     * admin-set (blank => the module generates a username).
     *
     * @return array<int, array{name: string, adminonly: bool, showorder: bool, description: string}>
     */
    public static function moduleFields(): array
    {
        return [
            ['name' => 'continuum_user_id', 'adminonly' => true, 'showorder' => false,
             'description' => 'Continuum user ID (managed by the continuum module)'],
            ['name' => 'continuum_library_names_cache', 'adminonly' => true, 'showorder' => false,
             'description' => 'Cached Continuum library names (managed by the continuum module)'],
            ['name' => 'desired_username', 'adminonly' => true, 'showorder' => false,
             'description' => 'Optional admin-set Continuum username (blank = auto-generated)'],
        ];
    }

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
