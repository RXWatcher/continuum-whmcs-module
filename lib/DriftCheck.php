<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

/**
 * Pure drift-comparison logic. Given the WHMCS-side expected state and
 * the Continuum-side observed state, return a list of human-readable
 * drift messages (empty when in sync). Called by DailyReconciler for
 * each service.
 */
final class DriftCheck
{
    private const INT_FIELDS = ['max_streams', 'max_transcodes', 'max_profiles'];
    private const BOOL_FIELDS = ['download_allowed', 'download_transcode_allowed'];

    /**
     * @param array<string, mixed> $whmcsExpected
     * @param array<string, mixed> $continuumObserved
     * @return string[] drift descriptions
     */
    public static function compare(
        int $serviceId,
        int $userId,
        array $whmcsExpected,
        array $continuumObserved
    ): array {
        $prefix = "service {$serviceId} → user {$userId}";
        $drifts = [];

        if (
            isset($whmcsExpected['enabled'])
            && ($continuumObserved['enabled'] ?? null) !== $whmcsExpected['enabled']
        ) {
            $drifts[] = "{$prefix}: enabled expected="
                . var_export($whmcsExpected['enabled'], true)
                . " but Continuum has " . var_export($continuumObserved['enabled'] ?? null, true);
        }

        if (
            isset($whmcsExpected['role'])
            && ($continuumObserved['role'] ?? null) !== $whmcsExpected['role']
        ) {
            $drifts[] = "{$prefix}: role expected={$whmcsExpected['role']}"
                . " but Continuum has " . (string)($continuumObserved['role'] ?? '');
        }

        if (isset($whmcsExpected['library_ids'])) {
            $exp = $whmcsExpected['library_ids'];
            sort($exp);
            $obs = $continuumObserved['library_ids'] ?? [];
            sort($obs);
            if ($exp !== $obs) {
                $drifts[] = "{$prefix}: library_ids expected=["
                    . implode(',', $exp) . "] but Continuum has [" . implode(',', $obs) . "]";
            }
        }

        foreach (self::INT_FIELDS as $f) {
            if (isset($whmcsExpected[$f]) && (int)($continuumObserved[$f] ?? 0) !== (int)$whmcsExpected[$f]) {
                $drifts[] = "{$prefix}: {$f} expected={$whmcsExpected[$f]}"
                    . " but Continuum has " . (int)($continuumObserved[$f] ?? 0);
            }
        }

        foreach (self::BOOL_FIELDS as $f) {
            if (isset($whmcsExpected[$f]) && ($continuumObserved[$f] ?? null) !== $whmcsExpected[$f]) {
                $drifts[] = "{$prefix}: {$f} expected="
                    . var_export($whmcsExpected[$f], true)
                    . " but Continuum has " . var_export($continuumObserved[$f] ?? null, true);
            }
        }

        if (isset($whmcsExpected['max_playback_quality'])) {
            // Compare canonically: the module uses '4k' while Continuum
            // stores '2160p', and 480p/720p both mean 1080p.
            $expQ = PlaybackQuality::canonical((string)$whmcsExpected['max_playback_quality']);
            $obsQ = PlaybackQuality::canonical((string)($continuumObserved['max_playback_quality'] ?? ''));
            if ($expQ !== $obsQ) {
                $drifts[] = "{$prefix}: max_playback_quality expected='{$expQ}'"
                    . " but Continuum has '{$obsQ}'";
            }
        }

        return $drifts;
    }
}
