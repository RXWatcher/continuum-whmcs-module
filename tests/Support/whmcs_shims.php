<?php

declare(strict_types=1);

/*
 * Shims for the WHMCS runtime. Bracketed namespaces let one file define
 * the `WHMCS\Database\Capsule` fake plus the global helper functions
 * (logActivity, localAPI, decrypt, …). All state lives in
 * Silo\WhmcsModule\Tests\Support\FakeWhmcs; the Capsule query builder
 * is the PSR-4 class Tests\Support\FakeQueryBuilder.
 *
 * This file is require()d by tests/bootstrap.php (not autoloaded).
 */

namespace WHMCS\Database {

    use Silo\WhmcsModule\Tests\Support\FakeQueryBuilder;

    class Capsule
    {
        public static function table(string $name): FakeQueryBuilder
        {
            return new FakeQueryBuilder($name);
        }
    }
}

namespace {

    use Silo\WhmcsModule\Tests\Support\FakeWhmcs;

    if (!function_exists('logActivity')) {
        function logActivity($message, $userid = 0): void
        {
            FakeWhmcs::$activityLog[] = (string)$message;
        }
    }

    if (!function_exists('logModuleCall')) {
        function logModuleCall(...$args): void
        {
            // no-op
        }
    }

    if (!function_exists('decrypt')) {
        function decrypt($value)
        {
            return $value;
        }
    }

    if (!function_exists('encrypt')) {
        function encrypt($value)
        {
            return $value;
        }
    }

    if (!function_exists('add_hook')) {
        function add_hook($name, $priority, $function): void
        {
            // no-op
        }
    }

    if (!function_exists('localAPI')) {
        function localAPI(string $action, array $params = [], $adminUsername = null): array
        {
            FakeWhmcs::$localApiCalls[] = ['action' => $action, 'params' => $params];

            $handler = FakeWhmcs::$localApiHandler;
            if ($handler !== null) {
                $r = $handler($action, $params);
                if ($r !== null) {
                    return $r;
                }
            }

            switch ($action) {
                case 'GetClientsProducts':
                    return [
                        'result' => 'success',
                        'products' => ['product' => [0 => [
                            'customfields' => ['customfield' => FakeWhmcs::$customFields],
                        ]]],
                    ];
                case 'UpdateClientProduct':
                    FakeWhmcs::$updateClientProduct[] = $params;
                    return ['result' => 'success'];
                default:
                    return ['result' => 'success'];
            }
        }
    }
}
