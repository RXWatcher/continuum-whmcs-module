<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

/**
 * Canonicalises playback-quality values to the three ceilings Continuum
 * actually enforces (verified in Continuum's access/quality.go
 * ParsePlaybackQualityPreset):
 *
 *   ''      unrestricted
 *   '1080p' standard — Continuum collapses 480p/720p/1080p to this
 *   '4k'    Continuum stores/normalises this as 2160p
 *
 * Outbound, Continuum accepts '', '1080p' and '4k' directly. Inbound it
 * returns '', '1080p' or '2160p'. Centralising the mapping here keeps the
 * config field, attribute mapper, client-area display and drift check in
 * agreement, and lets legacy 480p/720p product settings degrade to 1080p
 * (Continuum's real behaviour) instead of failing provisioning.
 */
final class PlaybackQuality
{
    public static function canonical(string $value): string
    {
        $v = str_replace([' ', '_', '-'], '', strtolower(trim($value)));

        return match ($v) {
            '4k', 'uhd', '2160p', '2160', '4320p', '4320' => '4k',
            '1080p', '1080', 'fhd', 'fullhd', 'hd',
            '720p', '720', '480p', '480', 'sd', 'standard' => '1080p',
            default => '', // '', any, unrestricted, none, unknown -> no cap
        };
    }

    public static function human(string $value): string
    {
        return match (self::canonical($value)) {
            '4k' => 'Up to 4K',
            '1080p' => 'Up to 1080p',
            default => 'Unrestricted',
        };
    }
}
