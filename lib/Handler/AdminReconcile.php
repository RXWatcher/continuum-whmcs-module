<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\HookContext;

final class AdminReconcile
{
    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        return (new ChangePackage($this->ctx))->handle($params);
    }
}
