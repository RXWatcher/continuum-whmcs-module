<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

/**
 * Case-folded substring match against a list of disallowed usernames:
 * a candidate is rejected if it equals or contains any listed word.
 *
 * Default ships in data/bad_words.default.txt. Operators replace (not
 * merge) the default by dropping bad_words.txt next to silo.php;
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

    /**
     * True if the candidate IS a listed word or CONTAINS one as a
     * substring (case-folded). Substring matching means "fuck" in the
     * list also rejects "fuckers"/"assfuck"; operators tune false
     * positives by curating the list (short, ambiguous fragments catch
     * more). An exact-match hit short-circuits the scan.
     */
    public function contains(string $candidate): bool
    {
        $candidate = strtolower($candidate);
        if (isset($this->words[$candidate])) {
            return true;
        }
        foreach ($this->words as $word => $_) {
            // PHP casts numeric-string array keys to int — normalise back.
            $word = (string)$word;
            if ($word !== '' && str_contains($candidate, $word)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] alphabetical, lowercase */
    public function entries(): array
    {
        $out = array_keys($this->words);
        sort($out);
        return $out;
    }
}
