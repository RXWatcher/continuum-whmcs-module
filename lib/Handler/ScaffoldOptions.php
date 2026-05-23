<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Whmcs\ConfigOptionScaffolder;

/**
 * Backs the admin "Scaffold Configurable Options" button. Pulls the live
 * Silo libraries (best-effort) so per-library opt-ins are scaffolded
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
        } catch (SiloApiException $e) {
            // Non-fatal: scaffold everything except per-library opt-ins.
        }

        try {
            $result = (new ConfigOptionScaffolder())->scaffold($libraries);
        } catch (\Throwable $e) {
            return 'Scaffold failed: ' . $e->getMessage();
        }

        if (function_exists('logActivity')) {
            logActivity(sprintf(
                'silo: scaffolded "%s" — created: %s | skipped: %s | libraries: %d',
                $result['group'],
                implode(', ', $result['created']) ?: 'none',
                count($result['skipped']) . ' existing',
                count($libraries)
            ));
        }

        return 'success';
    }
}
