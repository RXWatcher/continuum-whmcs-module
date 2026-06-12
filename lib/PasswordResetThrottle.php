<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use WHMCS\Database\Capsule;

/**
 * Per-service cooldown for the client-area "reset password & sign out"
 * action. A reset revokes every Silo session, so an unthrottled button
 * lets a customer (or anyone holding their client-area session) spam it
 * to repeatedly kick the account off all devices.
 *
 * Best-effort, mirroring HomeStore: the table is created on demand and
 * every method degrades to "not throttled" on any DB trouble — the
 * cooldown is an abuse guard, never a correctness dependency.
 */
final class PasswordResetThrottle
{
    private const TABLE = 'mod_silo_pw_reset';
    private bool $ensured = false;

    /**
     * Seconds the caller must still wait before another reset is allowed.
     * 0 means a reset may proceed now (also when $cooldownSeconds <= 0,
     * which disables throttling). The cooldown is policy supplied by the
     * caller (per-product config) — the store only remembers timestamps.
     * No side effects — call record() after a reset actually succeeds.
     */
    public function blockedFor(int $serviceId, int $cooldownSeconds): int
    {
        if ($serviceId <= 0 || $cooldownSeconds <= 0) {
            return 0; // no key to throttle on, or throttling disabled
        }
        $last = $this->lastResetAt($serviceId);
        if ($last === null) {
            return 0;
        }
        $remaining = $cooldownSeconds - (time() - $last);
        return $remaining > 0 ? $remaining : 0;
    }

    /** Stamp "a reset just happened" for this service. */
    public function record(int $serviceId): void
    {
        if ($serviceId <= 0) {
            return;
        }
        $this->ensure();
        try {
            Capsule::table(self::TABLE)->updateOrInsert(
                ['relid' => $serviceId],
                ['last_reset_at' => time()]
            );
        } catch (\Throwable $e) {
            // best-effort: a missed stamp just means no cooldown next time
        }
    }

    private function lastResetAt(int $serviceId): ?int
    {
        $this->ensure();
        try {
            $row = Capsule::table(self::TABLE)->where('relid', $serviceId)->first();
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === null || !isset($row->last_reset_at)) {
            return null;
        }
        return (int)$row->last_reset_at;
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
                    $t->integer('relid')->primary();
                    $t->integer('last_reset_at');
                });
            }
        } catch (\Throwable $e) {
            // No schema builder (tests) or insufficient grants — reads/writes
            // still work against an existing table, otherwise no throttling.
        }
    }
}
