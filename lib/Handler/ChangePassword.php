<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;

final class ChangePassword
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return 'No Continuum user is linked to this service.';
        }
        $password = Params::password($params);
        if ($password === '') {
            return 'WHMCS did not provide a password to change.';
        }
        try {
            $this->ctx->client()->updateUser($userId, array_merge(
                ['password' => $password],
                $this->syncFields($params)
            ));
        } catch (ContinuumApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        return 'success';
    }
}
