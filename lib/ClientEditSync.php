<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use Silo\WhmcsModule\Silo\ClientInterface;

/**
 * Pure email-rename driver. Given old/new emails and a Silo admin
 * client, look up the user by old email and push the new email.
 *
 * Wired from hooks.php's ClientEdit listener — the listener walks
 * tblservers for silo-typed servers and instantiates one of these
 * per server. The dual-linkage in Identity::resolve already heals lazily
 * on the next module hook, but this hook makes the rename proactive so
 * Silo reflects the new email immediately.
 */
final class ClientEditSync
{
    public const RESULT_NOOP = 'noop';
    public const RESULT_NO_USER = 'no_user_on_this_server';
    public const RESULT_UPDATED = 'updated';

    public function __construct(private ClientInterface $client)
    {
    }

    public function rename(string $oldEmail, string $newEmail): string
    {
        $oldEmail = strtolower(trim($oldEmail));
        $newEmail = strtolower(trim($newEmail));
        if ($oldEmail === '' || $newEmail === '' || $oldEmail === $newEmail) {
            return self::RESULT_NOOP;
        }
        $user = $this->client->findUserByEmail($oldEmail);
        if (!is_array($user) || !isset($user['id'])) {
            return self::RESULT_NO_USER;
        }
        $this->client->updateUser((int)$user['id'], ['email' => $newEmail]);
        return self::RESULT_UPDATED;
    }
}
