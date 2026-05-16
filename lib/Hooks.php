<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Handler\AdminReconcile;
use Continuum\WhmcsModule\Handler\AdminResetPassword;
use Continuum\WhmcsModule\Handler\AdminServicesTab;
use Continuum\WhmcsModule\Handler\ChangePackage;
use Continuum\WhmcsModule\Handler\ChangePassword;
use Continuum\WhmcsModule\Handler\ClientArea;
use Continuum\WhmcsModule\Handler\CreateAccount;
use Continuum\WhmcsModule\Handler\SetEnabled;

/**
 * Thin dispatch facade — wires WHMCS hook entry points to the per-event
 * Handler/* classes. continuum.php may also construct handlers directly.
 */
final class Hooks
{
    public function __construct(private ?HookContext $injectedContext = null)
    {
    }

    private function context(array $params): HookContext
    {
        return $this->injectedContext ?? HookContext::fromParams($params);
    }

    public function createAccount(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new CreateAccount($ctx))->handle($params);
    }

    public function suspendAccount(array $params): string
    {
        return $this->setEnabled($params, false);
    }

    public function unsuspendAccount(array $params): string
    {
        return $this->setEnabled($params, true);
    }

    public function terminateAccount(array $params): string
    {
        return $this->setEnabled($params, false);
    }

    public function changePassword(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new ChangePassword($ctx))->handle($params);
    }

    public function changePackage(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Product config error: ' . $e->getMessage();
        }
        return (new ChangePackage($ctx))->handle($params);
    }

    public function adminReconcile(array $params): string
    {
        return $this->changePackage($params);
    }

    public function adminResetPassword(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new AdminResetPassword($ctx))->handle($params);
    }

    public function adminServicesTabFields(array $params): array
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return ['Continuum status' => 'Configuration error: ' . htmlspecialchars($e->getMessage())];
        }
        return (new AdminServicesTab($ctx))->handle($params);
    }

    public function clientArea(array $params): array
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return ['templatefile' => 'clientarea', 'vars' => ['error' => $e->getMessage()]];
        }
        return (new ClientArea($ctx))->handle($params);
    }

    private function setEnabled(array $params, bool $enabled): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new SetEnabled($ctx))->handle($params, $enabled);
    }
}
