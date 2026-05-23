<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;

/**
 * Backs the "Test Connection" button on the WHMCS Servers page.
 *
 * Probes a cheap, authenticated endpoint (the libraries list — a single
 * request, no pagination) so the result exercises DNS, TLS, the
 * configured port, and the API key in one round trip. Returns the
 * `['success' => bool, 'error' => string]` shape WHMCS expects.
 */
final class TestConnection
{
    public function __construct(private HookContext $ctx)
    {
    }

    /** @return array{success: bool, error: string} */
    public function handle(): array
    {
        try {
            $this->ctx->client()->listLibraries();
        } catch (SiloApiException $e) {
            return ['success' => false, 'error' => $this->describe($e)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        return ['success' => true, 'error' => ''];
    }

    private function describe(SiloApiException $e): string
    {
        return match (true) {
            in_array($e->httpStatus(), [401, 403], true) =>
                'Authentication failed — check the admin API key in the '
                . 'Password / Access Hash field. (' . $e->getMessage() . ')',
            $e->httpStatus() >= 500 =>
                'Silo reachable but returned a server error. (' . $e->getMessage() . ')',
            default => $e->getMessage(),
        };
    }
}
