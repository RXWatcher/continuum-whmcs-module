<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Config;

final class ConfigurableOptionsRuleSet
{
    /** @var ConfigurableOptionsRule[] */
    private array $rules;

    private const SCHEMA = [
        'role'                       => ['set' => 'role_string'],
        'library_ids'                => ['set' => 'int_array', 'append' => 'int_array'],
        'max_streams'                => ['set' => 'int', 'add' => 'int'],
        'max_transcodes'             => ['set' => 'int', 'add' => 'int'],
        'max_profiles'               => ['set' => 'int', 'add' => 'int'],
        'download_allowed'           => ['set' => 'bool'],
        'download_transcode_allowed' => ['set' => 'bool'],
        'max_playback_quality'       => ['set' => 'quality_string'],
    ];

    /** @param ConfigurableOptionsRule[] $rules */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromJson(string $json): self
    {
        $json = trim($json);
        if ($json === '') {
            return new self([]);
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Malformed JSON: ' . json_last_error_msg());
        }
        if (!is_array($decoded) || (count($decoded) > 0 && !self::isList($decoded))) {
            throw new \InvalidArgumentException('configurable_options_map must be a JSON array of rule objects');
        }

        $rules = [];
        foreach ($decoded as $idx => $raw) {
            $rules[] = self::validateRule($idx, $raw);
        }
        return new self($rules);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function validateRule(int $idx, mixed $raw): ConfigurableOptionsRule
    {
        $prefix = "rule {$idx}: ";
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($prefix . 'must be an object');
        }
        foreach (['option_name', 'match', 'attribute', 'op', 'value'] as $required) {
            if (!array_key_exists($required, $raw)) {
                throw new \InvalidArgumentException($prefix . "missing field '{$required}'");
            }
        }

        $attribute = $raw['attribute'];
        if (!isset(self::SCHEMA[$attribute])) {
            throw new \InvalidArgumentException(
                $prefix . "unknown attribute '{$attribute}' — allowed: " . implode(', ', array_keys(self::SCHEMA))
            );
        }
        $op = $raw['op'];
        if (!isset(self::SCHEMA[$attribute][$op])) {
            throw new \InvalidArgumentException(
                $prefix . "op '{$op}' not allowed on attribute '{$attribute}' — allowed: "
                . implode(', ', array_keys(self::SCHEMA[$attribute]))
            );
        }

        $expectedType = self::SCHEMA[$attribute][$op];
        self::validateValue($prefix, $expectedType, $raw['value']);

        return new ConfigurableOptionsRule(
            (string)$raw['option_name'],
            (string)$raw['match'],
            $attribute,
            $op,
            $raw['value'],
        );
    }

    private static function validateValue(string $prefix, string $expectedType, mixed $value): void
    {
        switch ($expectedType) {
            case 'int':
                if (!is_int($value)) {
                    throw new \InvalidArgumentException($prefix . 'value must be integer');
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException($prefix . 'value must be boolean');
                }
                break;
            case 'int_array':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException($prefix . 'value must be array of integers');
                }
                foreach ($value as $item) {
                    if (!is_int($item)) {
                        throw new \InvalidArgumentException($prefix . 'all entries in value array must be integers');
                    }
                }
                break;
            case 'role_string':
                if (!is_string($value) || !in_array($value, ['user', 'admin'], true)) {
                    throw new \InvalidArgumentException($prefix . "value must be 'user' or 'admin'");
                }
                break;
            case 'quality_string':
                $allowed = ['', '4k', '1080p', '720p', '480p'];
                if (!is_string($value) || !in_array($value, $allowed, true)) {
                    throw new \InvalidArgumentException(
                        $prefix . "value must be one of: '', 4k, 1080p, 720p, 480p"
                    );
                }
                break;
            default:
                throw new \LogicException("unhandled expected type {$expectedType}");
        }
    }

    /** @return ConfigurableOptionsRule[] */
    public function rules(): array
    {
        return $this->rules;
    }
}
