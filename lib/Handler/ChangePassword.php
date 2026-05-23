<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;

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
            return 'No Silo user is linked to this service.';
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
        } catch (SiloApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        return 'success';
    }
}
