<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;
use WHMCS\Database\Capsule;

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

        // A single customer can hold several WHMCS services that all
        // resolve (by email/id) to ONE Silo user. Deleting the user here
        // would destroy the account the customer's other still-active
        // service depends on. When a sibling is still active, disable
        // instead of deleting — same fail-safe as delete_on_terminate=OFF.
        if ($this->userSharedByAnotherActiveService(Params::serviceId($params), $userId)) {
            if (function_exists('logActivity')) {
                logActivity(
                    'silo: not deleting Silo user ' . $userId . ' on terminate of service '
                    . Params::serviceId($params) . ' — still in use by another active service; disabling instead'
                );
            }
            return (new SetEnabled($this->ctx))->handle($params, false);
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

    /**
     * True if a WHMCS service OTHER than $serviceId is still Active and
     * linked to the same Silo $userId (via the silo_user_id custom field).
     * Fails safe to false on any DB trouble: an undetected share falls
     * back to the legacy delete, which is no worse than before this guard
     * existed.
     */
    private function userSharedByAnotherActiveService(int $serviceId, int $userId): bool
    {
        try {
            $fieldIds = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', 'silo_user_id')
                ->get();

            $siblingServiceIds = [];
            foreach ($fieldIds as $field) {
                $values = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', (int)($field->id ?? 0))
                    ->where('value', (string)$userId)
                    ->get();
                foreach ($values as $v) {
                    $relid = (int)($v->relid ?? 0);
                    if ($relid > 0 && $relid !== $serviceId) {
                        $siblingServiceIds[$relid] = true;
                    }
                }
            }

            foreach (array_keys($siblingServiceIds) as $sid) {
                $status = Capsule::table('tblhosting')->where('id', $sid)->value('domainstatus');
                if (strtolower((string)$status) === 'active') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
}
