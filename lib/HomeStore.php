<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use WHMCS\Database\Capsule;

/**
 * Durable customer → Continuum-home pointer (email → serverid/userid).
 *
 * Purely a cache/optimisation for ServerRegistry: it makes the
 * cross-server scan deterministic (probe the known home first) and cheap
 * (skip it entirely on a hit). Correctness never depends on it — every
 * method is best-effort and degrades to "no pointer", which just falls
 * back to a full scan. The table is created on demand the same way the
 * module already writes schema directly (CustomFieldProvisioner /
 * ConfigOptionScaffolder).
 */
final class HomeStore
{
    private const TABLE = 'mod_continuum_home';
    private bool $ensured = false;

    /** @return array{serverid:int, userid:int}|null */
    public function get(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $this->ensure();
        try {
            $row = Capsule::table(self::TABLE)->where('email', $email)->first();
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        return ['serverid' => (int)($row->serverid ?? 0), 'userid' => (int)($row->userid ?? 0)];
    }

    public function put(string $email, int $serverId, int $userId): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || $serverId <= 0 || $userId <= 0) {
            return;
        }
        $this->ensure();
        try {
            Capsule::table(self::TABLE)->updateOrInsert(
                ['email' => $email],
                ['serverid' => $serverId, 'userid' => $userId, 'updated_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            // best-effort: a missing pointer just forces a scan next time
        }
    }

    private function ensure(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, static function ($t): void {
                    $t->string('email')->primary();
                    $t->integer('serverid');
                    $t->integer('userid');
                    $t->dateTime('updated_at');
                });
            }
        } catch (\Throwable $e) {
            // No schema builder (e.g. unit tests) or insufficient grants —
            // get()/put() still work against an existing table, otherwise
            // the feature degrades to scan-only.
        }
    }
}
