<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

/**
 * Generates 7-character lowercase usernames: 4 letters + 3 digits
 * (e.g. "abcd232"). Namespace = 26⁴ × 10³ ≈ 457M. See spec §3.4.
 *
 * Uses random_int (CSPRNG) — overkill for usernames but the right
 * default since it removes any "did you seed?" question.
 */
final class UsernameGenerator
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';
    private const DIGITS  = '0123456789';

    public static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::LETTERS[random_int(0, 25)];
        }
        for ($i = 0; $i < 3; $i++) {
            $out .= self::DIGITS[random_int(0, 9)];
        }
        return $out;
    }

    public static function generateFromName(
        string $firstName,
        string $lastName,
        ?callable $randomInt = null
    ): ?string {
        $first = self::normaliseNamePart($firstName);
        $last = self::normaliseNamePart($lastName);

        if ($first === '' || $last === '') {
            return null;
        }

        $suffix = $randomInt !== null ? $randomInt() : random_int(0, 999);
        if (!is_int($suffix) || $suffix < 0 || $suffix > 999) {
            throw new \InvalidArgumentException('Username suffix must be between 0 and 999');
        }

        return $first[0] . substr($last, 0, 4) . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
    }

    public static function normaliseNamePart(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transliterated !== false) {
                $value = $transliterated;
            }
        }

        return preg_replace('/[^a-z]/', '', strtolower($value)) ?? '';
    }
}
