<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\Identity\Params;

class Identity
{
    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * Three-tier resolution. Returns the Continuum userId, or null if no
     * signal matches.
     *
     *   1. `continuum_user_id` custom field → verify via getUser, then
     *      cross-check the returned user's email against the WHMCS client
     *      email. A mismatch (stale or hand-edited custom field pointing
     *      at someone else's account) falls through to tier 2 rather
     *      than letting subsequent writes hit the wrong user.
     *   2. Lowercased WHMCS client email → findUserByEmail. Heals if the
     *      stored ID was deleted/wrong.
     *   3. WHMCS service username (`$params['username']`) → findUserByUsername.
     *      Heals if both the ID and the email changed.
     *
     * Continuum stores emails case-sensitively in plain `text` columns, so
     * the lowercasing happens here AND in Client::findUserByEmail.
     *
     * In default mode any Continuum API trouble during tier 2/3 is
     * swallowed and returned as null, so display-only handlers (ClientArea,
     * AdminServicesTab, etc.) degrade gracefully on an outage. Pass
     * `$strict = true` from write paths that must NOT proceed on a
     * negative they can't trust (notably CreateAccount, which would
     * otherwise create a duplicate user); in strict mode the underlying
     * ContinuumApiException is re-thrown.
     *
     * @param array<string, mixed> $params
     */
    public function resolve(array $params, bool $strict = false): ?int
    {
        $idFromField = Params::continuumUserId($params);
        $expectedEmail = Params::email($params);

        if ($idFromField !== null) {
            try {
                $user = $this->client->getUser($idFromField);
                if (isset($user['id']) && $this->userMatchesExpectedEmail($user, $expectedEmail)) {
                    return (int)$user['id'];
                }
                // Either no id returned, or the returned user belongs to
                // someone else — fall through and let tier 2 find the
                // real user by email. ensureLinkage in the handler will
                // then rewrite the stale custom field.
            } catch (ContinuumApiException $e) {
                // 404 = the ID is genuinely stale; fall through and heal.
                // Anything else means we don't really know — in strict
                // mode escalate rather than risk a duplicate-create.
                if ($strict && $e->httpStatus() !== 404) {
                    throw $e;
                }
            }
        }

        try {
            if ($expectedEmail !== '') {
                $user = $this->client->findUserByEmail($expectedEmail);
                if (is_array($user) && isset($user['id'])) {
                    return (int)$user['id'];
                }
            }

            $username = Params::username($params);
            if ($username !== '') {
                $user = $this->client->findUserByUsername($username);
                if (is_array($user) && isset($user['id'])) {
                    return (int)$user['id'];
                }
            }
        } catch (ContinuumApiException $e) {
            if ($strict) {
                throw $e;
            }
            // Default mode: an unreachable API looks the same as "no
            // match" so display/idempotent-update handlers don't blow
            // up on a transient outage.
        }

        return null;
    }

    /**
     * Trust the tier-1 ID only when the returned user's email matches
     * the WHMCS client email (case-insensitive). If either side has no
     * email to compare, fall back to trusting the ID — the alternative
     * would break installs where Continuum responses don't include the
     * email field, with no upside.
     *
     * @param array<string, mixed> $user
     */
    private function userMatchesExpectedEmail(array $user, string $expectedEmail): bool
    {
        $returned = strtolower(trim((string)($user['email'] ?? '')));
        if ($expectedEmail === '' || $returned === '') {
            return true;
        }
        return $returned === $expectedEmail;
    }

}
