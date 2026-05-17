<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ProductConfig;

class AttributeMapper
{
    /**
     * Seed attributes from product config, then apply WHMCS configurable
     * options by convention. Option names are matched case-insensitively after
     * punctuation is normalized to spaces.
     *
     * @param array<int, array{name: string, value: string}> $serviceOptions
     * @return array<string, mixed>
     */
    public function apply(ProductConfig $pc, array $serviceOptions): array
    {
        $attrs = [
            // Every Continuum user is a 'user'. Continuum's createUser
            // requires a role, and the module sells no admin accounts, so
            // this is fixed and intentionally not configurable.
            'role' => 'user',
            'library_ids' => $pc->libraryIds(),
            'max_streams' => $pc->maxStreams(),
            'max_transcodes' => $pc->maxTranscodes(),
            'max_profiles' => $pc->maxProfiles(),
            'download_allowed' => $pc->downloadAllowed(),
            'download_transcode_allowed' => $pc->downloadTranscodeAllowed(),
            'max_playback_quality' => $pc->maxPlaybackQuality(),
        ];

        foreach ($serviceOptions as $opt) {
            $name = $this->normaliseName((string)($opt['name'] ?? ''));
            $value = trim((string)($opt['value'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($name === 'extra streams') {
                $attrs['max_streams'] += $this->intValue($value);
                continue;
            }
            if ($name === 'max streams') {
                $attrs['max_streams'] = $this->intValue($value);
                continue;
            }
            if ($name === 'extra transcodes') {
                $attrs['max_transcodes'] += $this->intValue($value);
                continue;
            }
            if ($name === 'max transcodes') {
                $attrs['max_transcodes'] = $this->intValue($value);
                continue;
            }
            if ($name === 'extra profiles') {
                $attrs['max_profiles'] += $this->intValue($value);
                continue;
            }
            if ($name === 'max profiles') {
                $attrs['max_profiles'] = $this->intValue($value);
                continue;
            }

            if (in_array($name, ['download allowed', 'downloads allowed', 'downloads'], true)) {
                $attrs['download_allowed'] = $this->boolValue($value);
                continue;
            }
            if (in_array($name, ['download transcode allowed', 'download transcodes allowed'], true)) {
                $attrs['download_transcode_allowed'] = $this->boolValue($value);
                continue;
            }

            if (in_array($name, ['max playback quality', 'playback quality'], true)) {
                $attrs['max_playback_quality'] = $this->qualityValue($value);
                continue;
            }
            if ($name === '4k streaming' && $this->boolValue($value)) {
                $attrs['max_playback_quality'] = '4k';
                continue;
            }

            if (in_array($name, ['library ids', 'libraries', 'library access', 'library pack'], true)) {
                $attrs['library_ids'] = $this->appendLibraryIds($attrs['library_ids'], $this->intListValue($value));
                continue;
            }
            if (preg_match('/^library(?: id)? ([0-9]+)$/', $name, $m) && $this->boolValue($value)) {
                $attrs['library_ids'] = $this->appendLibraryIds($attrs['library_ids'], [(int)$m[1]]);
            }
        }

        // Continuum treats library_ids = null as "all libraries" and an
        // empty array as "no libraries" (verified in Continuum source:
        // access/resolver.go LibrariesRestricted = user.LibraryIDs != nil).
        // So when nothing is listed on the product or via configurable
        // options, send null to grant all libraries — never [].
        if ($attrs['library_ids'] === []) {
            $attrs['library_ids'] = null;
        }

        // Continuum rejects max_profiles < 1 (400). Clamp so a 0/blank
        // product setting can't hard-fail provisioning.
        if (($attrs['max_profiles'] ?? 0) < 1) {
            $attrs['max_profiles'] = 1;
        }

        // Transcoded downloads are meaningless without downloads. Keep
        // them off whenever downloads are off — combined with the 'no'
        // default on the config option, transcode-downloads are
        // disabled by default and can't be on while downloads are off.
        if ($attrs['download_allowed'] === false) {
            $attrs['download_transcode_allowed'] = false;
        }

        return $attrs;
    }

    private function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function intValue(string $value): int
    {
        if (preg_match('/-?\d+/', $value, $m) !== 1) {
            return 0;
        }
        return max(0, (int)$m[0]);
    }

    /** @return int[] */
    private function intListValue(string $value): array
    {
        preg_match_all('/\d+/', $value, $m);
        return array_map('intval', $m[0] ?? []);
    }

    private function boolValue(string $value): bool
    {
        return !$this->isFalsey($value);
    }

    private function isFalsey(string $value): bool
    {
        $v = strtolower(trim($value));
        return in_array($v, ['', '0', 'no', 'false', 'off', 'none', 'disabled', 'n/a'], true);
    }

    private function qualityValue(string $value): string
    {
        return PlaybackQuality::canonical($value);
    }

    /**
     * @param int[] $existing
     * @param int[] $additional
     * @return int[]
     */
    private function appendLibraryIds(array $existing, array $additional): array
    {
        foreach ($additional as $id) {
            if ($id > 0 && !in_array($id, $existing, true)) {
                $existing[] = $id;
            }
        }
        return $existing;
    }
}
