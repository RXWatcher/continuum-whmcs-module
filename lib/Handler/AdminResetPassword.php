<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;

final class AdminResetPassword
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
        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            $this->ctx->client()->updateUser($userId, array_merge(
                ['password' => $password],
                $this->syncFields($params)
            ));
        } catch (SiloApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        try {
            localAPI('UpdateClientProduct', [
                'serviceid' => Params::serviceId($params),
                'servicepassword' => $password,
            ]);
        } catch (\Throwable $e) {
            return 'success (warning: failed to write back password to WHMCS service: ' . $e->getMessage() . ')';
        }
        return 'success';
    }
}
