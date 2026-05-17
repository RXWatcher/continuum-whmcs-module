<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;

/**
 * Customer-initiated Continuum password reset (client-area button).
 *
 * Changing the password via Continuum's admin updateUser also revokes
 * every existing session server-side (Continuum's
 * updateRequiresSessionRevocation), so this single action doubles as
 * "sign out all devices". The new password is shown once in the returned
 * status string and written back to the WHMCS service.
 */
final class ClientResetPassword
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return 'Your Continuum account is not linked yet — contact support.';
        }

        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        try {
            $this->ctx->client()->updateUser($userId, array_merge(
                ['password' => $password],
                $this->syncFields($params)
            ));
        } catch (ContinuumApiException $e) {
            return 'Could not reset your password right now: ' . $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);

        $tail = ' You have been signed out on all devices — sign in again with the new password.';
        try {
            localAPI('UpdateClientProduct', [
                'serviceid' => Params::serviceId($params),
                'servicepassword' => $password,
            ]);
        } catch (\Throwable $e) {
            return 'Your new Continuum password is: ' . $password
                . ' (save it now — it could not be stored on this service).' . $tail;
        }

        return 'Your new Continuum password is: ' . $password
            . ' — it has also been saved to this service.' . $tail;
    }
}
