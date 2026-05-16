<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;

final class SetEnabled
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params, bool $enabled): string
    {
        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return 'No Continuum user is linked to this service. Run "Reconcile from WHMCS" first.';
        }
        try {
            $this->ctx->client()->updateUser($userId, array_merge(
                ['enabled' => $enabled],
                $this->syncFields($params)
            ));
        } catch (ContinuumApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        return 'success';
    }
}
