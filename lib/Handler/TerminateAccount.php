<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;

/**
 * WHMCS TerminateAccount.
 *
 * With "Delete Continuum user on termination" ON (the default), this
 * permanently deletes the Continuum user (profiles and watch history
 * included). With it OFF, it falls back to the legacy behaviour of only
 * disabling the user, exactly like Suspend. Suspend/Unsuspend never
 * delete.
 */
final class TerminateAccount
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        if (!ProductConfig::deleteOnTerminate($params)) {
            // Retain the Continuum user — just disable it.
            return (new SetEnabled($this->ctx))->handle($params, false);
        }

        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            // Nothing linked to delete — termination still succeeds.
            return 'success';
        }

        try {
            $this->ctx->client()->deleteUser($userId);
        } catch (ContinuumApiException $e) {
            if ($e->httpStatus() === 404) {
                return 'success'; // already gone
            }
            return $this->humanError($e);
        }

        // Best-effort: drop the stale linkage so the admin tab doesn't
        // show a dangling user id. Non-fatal.
        try {
            $this->ctx->customFields()->write(Params::serviceId($params), 'continuum_user_id', '');
        } catch (\Throwable $e) {
            // ignore
        }

        return 'success';
    }
}
