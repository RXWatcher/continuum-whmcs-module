<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Continuum\ClientInterface;

class Identity
{
    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * Three-tier resolution. Returns the Continuum userId, or null if no
     * signal matches.
     *
     *   1. `continuum_user_id` custom field → verify via getUser. Most stable.
     *   2. Lowercased WHMCS client email → findUserByEmail. Heals if the
     *      stored ID was deleted/wrong.
     *   3. WHMCS service username (`$params['username']`) → findUserByUsername.
     *      Heals if both the ID and the email changed.
     *
     * Continuum stores emails case-sensitively in plain `text` columns, so
     * the lowercasing happens here AND in Client::findUserByEmail.
     *
     * @param array<string, mixed> $params
     */
    public function resolve(array $params): ?int
    {
        $idFromField = self::idFromParams($params);
        if ($idFromField !== null) {
            try {
                $user = $this->client->getUser($idFromField);
                if (isset($user['id'])) {
                    return (int)$user['id'];
                }
            } catch (ContinuumApiException $e) {
                // Stale or otherwise unreachable — fall through to fallbacks.
            }
        }

        $email = self::emailFromParams($params);
        if ($email !== '') {
            $user = $this->client->findUserByEmail($email);
            if (is_array($user) && isset($user['id'])) {
                return (int)$user['id'];
            }
        }

        $username = self::usernameFromParams($params);
        if ($username !== '') {
            $user = $this->client->findUserByUsername($username);
            if (is_array($user) && isset($user['id'])) {
                return (int)$user['id'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    public static function idFromParams(array $params): ?int
    {
        $cf = $params['customfields'] ?? [];
        if (!is_array($cf) || !isset($cf['continuum_user_id'])) {
            return null;
        }
        $raw = trim((string)$cf['continuum_user_id']);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        return (int)$raw;
    }

    /** @param array<string, mixed> $params */
    public static function emailFromParams(array $params): string
    {
        $raw = (string)($params['clientsdetails']['email'] ?? '');
        return strtolower(trim($raw));
    }

    /** @param array<string, mixed> $params */
    public static function usernameFromParams(array $params): string
    {
        return trim((string)($params['username'] ?? ''));
    }
}
