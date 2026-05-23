<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;

/**
 * WHMCS TerminateAccount.
 *
 * With "Delete Silo user on termination" ON (the default), this
 * permanently deletes the Silo user (profiles and watch history
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
            // Retain the Silo user — just disable it.
            return (new SetEnabled($this->ctx))->handle($params, false);
        }

        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            // Nothing linked to delete — termination still succeeds.
            return 'success';
        }

        try {
            $this->ctx->client()->deleteUser($userId);
        } catch (SiloApiException $e) {
            if ($e->httpStatus() === 404) {
                return 'success'; // already gone
            }
            return $this->humanError($e);
        }

        // Best-effort: drop the stale linkage so the admin tab doesn't
        // show a dangling user id. Non-fatal.
        try {
            $this->ctx->customFields()->write(Params::serviceId($params), 'silo_user_id', '');
        } catch (\Throwable $e) {
            // ignore
        }

        // Drop the home pointer too — the user no longer exists anywhere,
        // so a future re-order is a genuine new account and there's no
        // home to re-home to. Leaving a stale pointer would make the
        // re-home path probe a deleted user before scanning, slowing
        // every re-order for nothing.
        $this->ctx->homeStore()->forget(Params::email($params));

        return 'success';
    }
}
