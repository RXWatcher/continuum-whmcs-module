<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;
use WHMCS\Database\Capsule;

final class ChangePackage
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        try {
            $pc = ProductConfig::fromParams($params);
        } catch (\InvalidArgumentException $e) {
            return 'Product config error: ' . $e->getMessage();
        }

        // "Reconcile from WHMCS" routes here too, so this doubles as the
        // manual fix for a product missing its custom fields.
        $this->ensureCustomFields($params);

        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return 'No Continuum user is linked to this service.';
        }

        $svcOptions = $this->normaliseConfigurableOptions($params['configoptions'] ?? []);
        $attrs = $this->ctx->mapper()->apply($pc, $svcOptions);

        // Reconcile/ChangePackage must assert the enabled state, not leave
        // it untouched: Continuum's updateUser is a partial PATCH, so an
        // omitted `enabled` would let a stale disabled user (e.g. from a
        // retain-on-terminate) survive a reconcile that reports success.
        //
        // WHMCS does NOT pass service status in $params (verified against
        // developers.whmcs.com — see docs/whmcs-contracts.md §1), so read
        // tblhosting.domainstatus directly, the same source DailyReconciler
        // uses. Fail safe: only disable on a DEFINITE non-active status;
        // an empty/missing row keeps the account enabled so a reconcile can
        // never silently lock out a working customer.
        $status = Capsule::table('tblhosting')
            ->where('id', Params::serviceId($params))
            ->value('domainstatus');
        $enabled = ($status === null || $status === '')
            || strtolower((string)$status) === 'active';

        try {
            $this->ctx->client()->updateUser(
                $userId,
                array_merge($attrs, ['enabled' => $enabled], $this->syncFields($params))
            );
        } catch (ContinuumApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);

        try {
            $this->ctx->customFields()->write(Params::serviceId($params), 'continuum_library_names_cache', '');
        } catch (\Throwable $e) {
            // Non-fatal.
        }
        return 'success';
    }
}
