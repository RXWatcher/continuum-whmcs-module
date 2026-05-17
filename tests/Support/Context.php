<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Support;

use Continuum\WhmcsModule\AttributeMapper;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;

/**
 * Builds a HookContext wired to the FakeClient but with the *real*
 * Identity, AttributeMapper, and CustomFieldStore — so handler tests
 * exercise the genuine resolution/mapping/custom-field code, with only
 * the HTTP boundary and WHMCS runtime faked.
 */
final class Context
{
    public static function make(FakeClient $client): HookContext
    {
        return new HookContext(
            $client,
            new Identity($client),
            new AttributeMapper(),
            new CustomFieldStore(),
        );
    }

    /**
     * A baseline WHMCS hook $params array. Override any key via $overrides
     * (recursively merged).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function params(array $overrides = []): array
    {
        return array_replace_recursive([
            'serviceid' => 7,
            'pid' => 3,
            'username' => 'svc_user',
            'password' => 'pw-123456',
            'clientsdetails' => [
                'firstname' => 'Jane',
                'email' => 'jane@example.com',
            ],
            'customfields' => [],
            'configoptions' => [],
        ], $overrides);
    }
}
