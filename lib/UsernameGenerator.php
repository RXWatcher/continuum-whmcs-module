<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

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
}
