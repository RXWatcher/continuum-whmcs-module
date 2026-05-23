<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;

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
            return 'No Silo user is linked to this service. Run "Reconcile from WHMCS" first.';
        }
        try {
            $this->ctx->client()->updateUser($userId, array_merge(
                ['enabled' => $enabled],
                $this->syncFields($params)
            ));
        } catch (SiloApiException $e) {
            return $this->humanError($e);
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        return 'success';
    }
}
