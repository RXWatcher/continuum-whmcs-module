<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;
use Continuum\WhmcsModule\Whmcs\CustomFieldProvisioner;

/**
 * Shared helpers across all Handler/* classes. Each handler is constructed
 * with a HookContext and uses these for the cross-cutting bits.
 */
trait HandlerHelpers
{
    /** @return array<string, string> */
    protected function syncFields(array $params): array
    {
        $out = [];
        $email = Params::email($params);
        if ($email !== '') {
            $out['email'] = $email;
        }
        $username = Params::username($params);
        if ($username !== '') {
            $out['username'] = $username;
        }
        return $out;
    }

    protected function ensureLinkage(HookContext $ctx, array $params, int $userId): void
    {
        $cf = $params['customfields'] ?? [];
        $current = is_array($cf) ? trim((string)($cf['continuum_user_id'] ?? '')) : '';
        if ($current === (string)$userId) {
            return;
        }
        try {
            $ctx->customFields()->write(Params::serviceId($params), 'continuum_user_id', (string)$userId);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity(
                    "continuum: failed to update continuum_user_id={$userId} for service "
                    . Params::serviceId($params) . ": " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Auto-create the product custom fields the module relies on so
     * admins don't have to add them by hand. Non-fatal: on failure the
     * existing "custom field is not declared" guidance still applies.
     */
    protected function ensureCustomFields(array $params, bool $includeDesiredUsername): void
    {
        $fields = [
            ['name' => 'continuum_user_id', 'adminonly' => true, 'showorder' => false,
             'description' => 'Continuum user ID (managed by the continuum module)'],
            ['name' => 'continuum_library_names_cache', 'adminonly' => true, 'showorder' => false,
             'description' => 'Cached Continuum library names (managed by the continuum module)'],
        ];
        if ($includeDesiredUsername) {
            $fields[] = ['name' => 'desired_username', 'adminonly' => false, 'showorder' => true,
                         'description' => 'Desired Continuum username'];
        }
        try {
            (new CustomFieldProvisioner())->ensure(
                Params::serviceId($params),
                (int)($params['pid'] ?? 0),
                $fields
            );
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('continuum: custom-field auto-provision failed for service '
                    . Params::serviceId($params) . ': ' . $e->getMessage());
            }
        }
    }

    protected function humanError(ContinuumApiException $e): string
    {
        if ($e->httpStatus() >= 500) {
            return 'Continuum returned a server error. Check Module Log for details.';
        }
        return 'Continuum: ' . $e->getMessage();
    }

    /** @return array<int, array{name: string, value: string}> */
    protected function normaliseConfigurableOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $value) {
            $out[] = ['name' => (string)$name, 'value' => (string)$value];
        }
        return $out;
    }

    protected function isDuplicateUsernameError(ContinuumApiException $e): bool
    {
        if ($e->httpStatus() !== 409) {
            return false;
        }
        $body = $e->body() ?? [];
        return ($body['error'] ?? '') === 'duplicate_username';
    }
}
