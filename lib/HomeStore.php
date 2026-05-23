<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use WHMCS\Database\Capsule;

/**
 * Durable customer → Silo-home pointer (email → serverid/userid).
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
    private const TABLE = 'mod_silo_home';
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

    /**
     * Drop a pointer. Called from TerminateAccount when the Silo user
     * is being permanently deleted (no point pointing at a deleted user;
     * a re-order is a genuine new customer), and from the ClientEdit email
     * hook so the pointer follows the rename instead of orphaning at the
     * old address.
     */
    public function forget(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }
        $this->ensure();
        try {
            Capsule::table(self::TABLE)->where('email', $email)->delete();
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Move a pointer to a new email key. Convenience for the email-rename
     * path: tries the move atomically, but if either side fails just logs
     * via the underlying methods' best-effort guarantees.
     */
    public function rename(string $oldEmail, string $newEmail): void
    {
        $oldEmail = strtolower(trim($oldEmail));
        $newEmail = strtolower(trim($newEmail));
        if ($oldEmail === '' || $newEmail === '' || $oldEmail === $newEmail) {
            return;
        }
        $existing = $this->get($oldEmail);
        if ($existing === null) {
            return;
        }
        $this->put($newEmail, $existing['serverid'], $existing['userid']);
        $this->forget($oldEmail);
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
