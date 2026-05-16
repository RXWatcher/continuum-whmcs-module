<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ConfigurableOptionsRuleSet;
use Continuum\WhmcsModule\Config\ProductConfig;

class AttributeMapper
{
    /**
     * Apply per spec §3.3:
     *   1. Seed from product config.
     *   2. For each rule in order: if any service option's (name,value)
     *      matches the rule's (option_name,match), apply the op to the
     *      running attribute set.
     *
     * @param array<int, array{name: string, value: string}> $serviceOptions
     * @return array<string, mixed>
     */
    public function apply(ProductConfig $pc, ConfigurableOptionsRuleSet $rs, array $serviceOptions): array
    {
        $attrs = [
            'role' => $pc->role(),
            'library_ids' => $pc->libraryIds(),
            'max_streams' => $pc->maxStreams(),
            'max_transcodes' => $pc->maxTranscodes(),
            'max_profiles' => $pc->maxProfiles(),
            'download_allowed' => $pc->downloadAllowed(),
            'download_transcode_allowed' => $pc->downloadTranscodeAllowed(),
            'max_playback_quality' => $pc->maxPlaybackQuality(),
        ];

        foreach ($rs->rules() as $rule) {
            $matched = false;
            foreach ($serviceOptions as $opt) {
                if (($opt['name'] ?? '') === $rule->optionName() && ($opt['value'] ?? '') === $rule->match()) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            $attr = $rule->attribute();
            $val = $rule->value();
            switch ($rule->op()) {
                case 'set':
                    $attrs[$attr] = $val;
                    break;
                case 'add':
                    $attrs[$attr] = (int)$attrs[$attr] + (int)$val;
                    break;
                case 'append':
                    $existing = is_array($attrs[$attr]) ? $attrs[$attr] : [];
                    foreach ($val as $item) {
                        if (!in_array($item, $existing, true)) {
                            $existing[] = $item;
                        }
                    }
                    $attrs[$attr] = $existing;
                    break;
            }
        }

        return $attrs;
    }
}
