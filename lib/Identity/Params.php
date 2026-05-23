<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Identity;

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

    public static function siloUserId(array $params): ?int
    {
        $cf = $params['customfields'] ?? [];
        if (!is_array($cf) || !isset($cf['silo_user_id'])) {
            return null;
        }
        $raw = trim((string)$cf['silo_user_id']);
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

    /**
     * The customer/admin-chosen username, found tolerantly.
     *
     * WHMCS keys $params['customfields'] by the custom field's display
     * name — and with the `internal | Customer Label` pipe convention
     * that key becomes the (untrimmed) text AFTER the pipe, not
     * `desired_username`. So an exact lookup is unreliable: match the
     * exact key first, then any key that normalises to "desired
     * username" or whose pre-pipe segment does. This lets admins label
     * the field anything ("desired_username | Enter your desired
     * username") without silently breaking the feature.
     */
    public static function desiredUsername(array $params): string
    {
        $cf = $params['customfields'] ?? [];
        if (!is_array($cf)) {
            return '';
        }
        if (isset($cf['desired_username'])) {
            return trim((string)$cf['desired_username']);
        }
        foreach ($cf as $key => $value) {
            $key = strtolower(trim((string)$key));
            $prePipe = strtolower(trim(explode('|', (string)$key, 2)[0]));
            $normalised = str_replace([' ', '-'], '_', $key);
            if (
                $prePipe === 'desired_username'
                || $normalised === 'desired_username'
                || (str_contains($key, 'desired') && str_contains($key, 'username'))
            ) {
                return trim((string)$value);
            }
        }
        return '';
    }
}
