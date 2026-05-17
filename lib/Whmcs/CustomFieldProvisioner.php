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
     * The internal custom fields the module relies on. All three are
     * created admin-only and NOT on the order form. desired_username is
     * included so the field exists, but order-form placement is left to
     * the admin: to let customers choose a username, an admin manually
     * ticks "Show on Order Form" on it in WHMCS (and enables "Allow
     * customer-chosen username" so the module consults it). The module
     * never moves it on/off the form, so a manual change is never undone.
     *
     * @return array<int, array{name:string, adminonly:bool, showorder:bool, description:string}>
     */
    public static function moduleFields(): array
    {
        return [
            ['name' => 'continuum_user_id', 'adminonly' => true, 'showorder' => false,
             'description' => 'Continuum user ID (managed by the continuum module)'],
            ['name' => 'continuum_library_names_cache', 'adminonly' => true, 'showorder' => false,
             'description' => 'Cached Continuum library names (managed by the continuum module)'],
            ['name' => 'desired_username|Enter your desired username',
             'adminonly' => true, 'showorder' => false,
             'description' => 'Chosen Continuum username (blank = auto-generated). '
                 . 'To collect this from customers, an admin enables Show on Order Form.'],
        ];
    }

    /** Canonical pre-pipe name of a WHMCS `name | Label` field spec. */
    private static function baseName(string $fieldName): string
    {
        return trim(explode('|', $fieldName, 2)[0]);
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
            // Create-if-missing only. Once a field exists the module
            // never changes it — so an admin who ticks "Show on Order
            // Form" (or relabels it) is never undone. A field is
            // considered present if the exact name, the bare pre-pipe
            // name, or any `base | Label` variant already exists, so a
            // pre-existing plain `desired_username` is never duplicated.
            $base = self::baseName($f['name']);
            $likeBase = str_replace('_', '\\_', $base);
            $exists = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', $productId)
                ->where(function ($q) use ($f, $base, $likeBase) {
                    $q->where('fieldname', $f['name'])
                        ->orWhere('fieldname', $base)
                        ->orWhere('fieldname', 'like', $likeBase . '|%')
                        ->orWhere('fieldname', 'like', $likeBase . ' |%');
                })
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
