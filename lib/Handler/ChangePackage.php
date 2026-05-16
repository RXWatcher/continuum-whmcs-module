<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;

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

        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return 'No Continuum user is linked to this service.';
        }

        $svcOptions = $this->normaliseConfigurableOptions($params['configoptions'] ?? []);
        $attrs = $this->ctx->mapper()->apply($pc, $svcOptions);

        try {
            $this->ctx->client()->updateUser($userId, array_merge($attrs, $this->syncFields($params)));
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
