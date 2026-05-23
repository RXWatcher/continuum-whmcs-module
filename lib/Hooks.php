<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use Silo\WhmcsModule\Handler\AdminResetPassword;
use Silo\WhmcsModule\Handler\AdminServicesTab;
use Silo\WhmcsModule\Handler\ChangePackage;
use Silo\WhmcsModule\Handler\ChangePassword;
use Silo\WhmcsModule\Handler\ClientArea;
use Silo\WhmcsModule\Handler\ClientResetPassword;
use Silo\WhmcsModule\Handler\CreateAccount;
use Silo\WhmcsModule\Handler\ScaffoldOptions;
use Silo\WhmcsModule\Handler\SetEnabled;
use Silo\WhmcsModule\Handler\TerminateAccount;
use Silo\WhmcsModule\Handler\TestConnection;

/**
 * Thin dispatch facade — wires WHMCS hook entry points to the per-event
 * Handler/* classes. silo.php may also construct handlers directly.
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

    /** @return array{success: bool, error: string} */
    public function testConnection(array $params): array
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        return (new TestConnection($ctx))->handle();
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
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new TerminateAccount($ctx))->handle($params);
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

    public function adminScaffoldOptions(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }
        return (new ScaffoldOptions($ctx))->handle();
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
            return ['Silo status' => 'Configuration error: ' . htmlspecialchars($e->getMessage())];
        }
        return (new AdminServicesTab($ctx))->handle($params);
    }

    public function clientArea(array $params): array
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            // Configuration detail (e.g. "API key required") must not
            // leak to customers — log it, show a generic message.
            if (function_exists('logActivity')) {
                logActivity('silo: client area unavailable (config error): ' . $e->getMessage());
            }
            return ['templatefile' => 'clientarea', 'vars' => [
                'error' => 'This service is temporarily unavailable. Please contact support.',
            ]];
        }
        return (new ClientArea($ctx))->handle($params);
    }

    public function clientAreaResetPassword(array $params): string
    {
        try {
            $ctx = $this->context($params);
        } catch (\InvalidArgumentException $e) {
            if (function_exists('logActivity')) {
                logActivity('silo: client password reset unavailable (config error): ' . $e->getMessage());
            }
            return 'This service is temporarily unavailable. Please contact support.';
        }
        return (new ClientResetPassword($ctx))->handle($params);
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
