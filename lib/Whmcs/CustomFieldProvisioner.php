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
    /** Username format the order form should accept (mirrors UsernameValidator). */
    private const USERNAME_REGEX = '/^[a-z0-9_-]{3,32}$/';

    /**
     * The internal custom fields the module relies on.
     *
     * continuum_user_id and continuum_library_names_cache are always
     * internal admin-only fields. desired_username depends on the
     * product's "Allow customer-chosen username" option:
     *
     *  - off (default): admin-only, not on the order form. Blank => the
     *    module generates a username; an admin may set one.
     *  - on: shown on the order form (optional) so the customer can pick
     *    their own username at checkout, validated by the same rules.
     *
     * @return array<int, array{name:string, adminonly:bool, showorder:bool, description:string, regexpr?:string}>
     */
    public static function moduleFields(bool $customerChosenUsername = false): array
    {
        return [
            ['name' => 'continuum_user_id', 'adminonly' => true, 'showorder' => false,
             'description' => 'Continuum user ID (managed by the continuum module)'],
            ['name' => 'continuum_library_names_cache', 'adminonly' => true, 'showorder' => false,
             'description' => 'Cached Continuum library names (managed by the continuum module)'],
            $customerChosenUsername
                ? ['name' => 'desired_username', 'adminonly' => false, 'showorder' => true,
                   'description' => 'Choose your username (3-32 chars: a-z, 0-9, _ or -). '
                       . 'Leave blank for an auto-generated one.',
                   'regexpr' => self::USERNAME_REGEX]
                : ['name' => 'desired_username', 'adminonly' => true, 'showorder' => false,
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
            $adminonly = $f['adminonly'] ? 'on' : '';
            $showorder = $f['showorder'] ? 'on' : '';
            $regexpr = $f['regexpr'] ?? '';

            $existing = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $productId)
                ->where('fieldname', $f['name'])
                ->first(['id', 'adminonly', 'showorder']);

            if ($existing !== null) {
                // Reconcile only desired_username's visibility so toggling
                // the product's "Allow customer-chosen username" option
                // moves it on/off the order form. Other fields, and any
                // field an admin created, are left untouched.
                if (
                    $f['name'] === 'desired_username'
                    && ($existing->adminonly !== $adminonly || $existing->showorder !== $showorder)
                ) {
                    Capsule::table('tblcustomfields')->where('id', $existing->id)->update([
                        'adminonly' => $adminonly,
                        'showorder' => $showorder,
                        'regexpr' => $regexpr,
                        'description' => $f['description'] ?? '',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
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
                'regexpr' => $regexpr,
                'adminonly' => $adminonly,
                'required' => '',
                'showorder' => $showorder,
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
