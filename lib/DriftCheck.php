<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

/**
 * Pure drift-comparison logic. Given the WHMCS-side expected state and
 * the Silo-side observed state, return a list of human-readable
 * drift messages (empty when in sync). Called by DailyReconciler for
 * each service.
 */
final class DriftCheck
{
    private const INT_FIELDS = ['max_streams', 'max_transcodes', 'max_profiles'];
    private const BOOL_FIELDS = ['download_allowed', 'download_transcode_allowed'];

    /**
     * @param array<string, mixed> $whmcsExpected
     * @param array<string, mixed> $siloObserved
     * @return string[] drift descriptions
     */
    public static function compare(
        int $serviceId,
        int $userId,
        array $whmcsExpected,
        array $siloObserved
    ): array {
        $prefix = "service {$serviceId} → user {$userId}";
        $drifts = [];

        if (
            isset($whmcsExpected['enabled'])
            && ($siloObserved['enabled'] ?? null) !== $whmcsExpected['enabled']
        ) {
            $drifts[] = "{$prefix}: enabled expected="
                . var_export($whmcsExpected['enabled'], true)
                . " but Silo has " . var_export($siloObserved['enabled'] ?? null, true);
        }

        if (
            isset($whmcsExpected['role'])
            && ($siloObserved['role'] ?? null) !== $whmcsExpected['role']
        ) {
            $drifts[] = "{$prefix}: role expected={$whmcsExpected['role']}"
                . " but Silo has " . (string)($siloObserved['role'] ?? '');
        }

        if (isset($whmcsExpected['library_ids'])) {
            $exp = $whmcsExpected['library_ids'];
            sort($exp);
            $obs = $siloObserved['library_ids'] ?? [];
            sort($obs);
            if ($exp !== $obs) {
                $drifts[] = "{$prefix}: library_ids expected=["
                    . implode(',', $exp) . "] but Silo has [" . implode(',', $obs) . "]";
            }
        }

        foreach (self::INT_FIELDS as $f) {
            if (isset($whmcsExpected[$f]) && (int)($siloObserved[$f] ?? 0) !== (int)$whmcsExpected[$f]) {
                $drifts[] = "{$prefix}: {$f} expected={$whmcsExpected[$f]}"
                    . " but Silo has " . (int)($siloObserved[$f] ?? 0);
            }
        }

        foreach (self::BOOL_FIELDS as $f) {
            if (isset($whmcsExpected[$f]) && ($siloObserved[$f] ?? null) !== $whmcsExpected[$f]) {
                $drifts[] = "{$prefix}: {$f} expected="
                    . var_export($whmcsExpected[$f], true)
                    . " but Silo has " . var_export($siloObserved[$f] ?? null, true);
            }
        }

        if (isset($whmcsExpected['max_playback_quality'])) {
            // Compare canonically: the module uses '4k' while Silo
            // stores '2160p', and 480p/720p both mean 1080p.
            $expQ = PlaybackQuality::canonical((string)$whmcsExpected['max_playback_quality']);
            $obsQ = PlaybackQuality::canonical((string)($siloObserved['max_playback_quality'] ?? ''));
            if ($expQ !== $obsQ) {
                $drifts[] = "{$prefix}: max_playback_quality expected='{$expQ}'"
                    . " but Silo has '{$obsQ}'";
            }
        }

        return $drifts;
    }
}
