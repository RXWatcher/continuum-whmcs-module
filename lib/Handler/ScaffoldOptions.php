<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Whmcs\ConfigOptionScaffolder;

/**
 * Backs the admin "Scaffold Configurable Options" button. Pulls the live
 * Continuum libraries (best-effort) so per-library opt-ins are scaffolded
 * for the real libraries, then creates the group/options/pricing/links.
 */
final class ScaffoldOptions
{
    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(): string
    {
        $libraries = [];
        try {
            foreach ($this->ctx->client()->listLibraries() as $lib) {
                if (isset($lib['id'])) {
                    $libraries[] = ['id' => (int)$lib['id'], 'name' => (string)($lib['name'] ?? '')];
                }
            }
        } catch (ContinuumApiException $e) {
            // Non-fatal: scaffold everything except per-library opt-ins.
        }

        try {
            $result = (new ConfigOptionScaffolder())->scaffold($libraries);
        } catch (\Throwable $e) {
            return 'Scaffold failed: ' . $e->getMessage();
        }

        if (function_exists('logActivity')) {
            logActivity(sprintf(
                'continuum: scaffolded "%s" — created: %s | skipped: %s | libraries: %d',
                $result['group'],
                implode(', ', $result['created']) ?: 'none',
                count($result['skipped']) . ' existing',
                count($libraries)
            ));
        }

        return 'success';
    }
}
