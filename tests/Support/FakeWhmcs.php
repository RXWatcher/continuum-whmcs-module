<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Support;

/**
 * In-memory stand-in for the WHMCS runtime state the module touches:
 * the `localAPI` surface, the `Capsule` query builder, and the activity
 * log. Tests drive it through the static fields / helpers; the global
 * shims in whmcs_shims.php read from here.
 *
 * Call FakeWhmcs::reset() in each test's setUp().
 */
final class FakeWhmcs
{
    /** @var array<string, array<int, array<string, mixed>>>  tableName => rows */
    public static array $tables = [];

    /** @var array<int, array{action: string, params: array<string, mixed>}> */
    public static array $localApiCalls = [];

    /** @var array<int, array<string, mixed>>  recorded UpdateClientProduct param sets */
    public static array $updateClientProduct = [];

    /** Optional callback($action, $params): ?array — return non-null to override. */
    public static $localApiHandler = null;

    /** @var array<int, array{id: int, name: string, value: string}>  GetClientsProducts custom fields */
    public static array $customFields = [];

    /** @var string[] */
    public static array $activityLog = [];

    /** When set, any Capsule::table() for this table throws (simulates a DB failure). */
    public static ?string $throwForTable = null;

    public static function reset(): void
    {
        self::$tables = [];
        self::$localApiCalls = [];
        self::$updateClientProduct = [];
        self::$localApiHandler = null;
        self::$customFields = [
            ['id' => 1, 'name' => 'continuum_user_id', 'value' => ''],
            ['id' => 2, 'name' => 'continuum_library_names_cache', 'value' => ''],
        ];
        self::$activityLog = [];
        self::$throwForTable = null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function seedTable(string $table, array $rows): void
    {
        self::$tables[$table] = $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public static function rows(string $table): array
    {
        return self::$tables[$table] ?? [];
    }

    public static function setCustomFieldValue(string $name, string $value): void
    {
        foreach (self::$customFields as &$f) {
            if ($f['name'] === $name) {
                $f['value'] = $value;
                return;
            }
        }
        unset($f);
        self::$customFields[] = ['id' => count(self::$customFields) + 1, 'name' => $name, 'value' => $value];
    }

    /** All param sets sent to localAPI for the given action. @return array<int, array<string, mixed>> */
    public static function apiCalls(string $action): array
    {
        $out = [];
        foreach (self::$localApiCalls as $c) {
            if ($c['action'] === $action) {
                $out[] = $c['params'];
            }
        }
        return $out;
    }
}
