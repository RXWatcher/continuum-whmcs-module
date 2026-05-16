<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

/**
 * Whole-word, case-folded match against a list of disallowed usernames.
 *
 * Default ships in data/bad_words.default.txt. Operators replace (not
 * merge) the default by dropping bad_words.txt next to continuum.php;
 * the module loads that if present.
 */
final class BadWordList
{
    /** @var array<string, bool> case-folded keys for O(1) contains() */
    private array $words;

    /** @param string[] $words */
    private function __construct(array $words)
    {
        $this->words = [];
        foreach ($words as $w) {
            $w = trim($w);
            if ($w === '' || str_starts_with($w, '#')) {
                continue;
            }
            $this->words[strtolower($w)] = true;
        }
    }

    public static function default(): self
    {
        return self::fromFile(__DIR__ . '/../data/bad_words.default.txt');
    }

    public static function fromFile(string $path): self
    {
        $lines = is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        return new self($lines);
    }

    /**
     * Resolve the list for a given module install root. If a sibling
     * bad_words.txt exists, use it (replacing the default). Otherwise
     * fall back to the default list.
     */
    public static function resolve(string $moduleDir): self
    {
        $override = rtrim($moduleDir, '/') . '/bad_words.txt';
        return is_readable($override) ? self::fromFile($override) : self::default();
    }

    public function contains(string $candidate): bool
    {
        return isset($this->words[strtolower($candidate)]);
    }

    /** @return string[] alphabetical, lowercase */
    public function entries(): array
    {
        $out = array_keys($this->words);
        sort($out);
        return $out;
    }
}
