<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Identity;

/**
 * Pure static extractors over WHMCS hook `$params`. No state, no IO.
 */
final class Params
{
    public static function email(array $params): string
    {
        return strtolower(trim((string)($params['clientsdetails']['email'] ?? '')));
    }

    public static function username(array $params): string
    {
        return trim((string)($params['username'] ?? ''));
    }

    public static function continuumUserId(array $params): ?int
    {
        $cf = $params['customfields'] ?? [];
        if (!is_array($cf) || !isset($cf['continuum_user_id'])) {
            return null;
        }
        $raw = trim((string)$cf['continuum_user_id']);
        return ($raw !== '' && ctype_digit($raw)) ? (int)$raw : null;
    }

    public static function serviceId(array $params): int
    {
        return (int)($params['serviceid'] ?? 0);
    }

    public static function password(array $params): string
    {
        return (string)($params['password'] ?? '');
    }
}
