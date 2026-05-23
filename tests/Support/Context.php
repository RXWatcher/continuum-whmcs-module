<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Support;

use Silo\WhmcsModule\AttributeMapper;
use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\Silo\ClientInterface;
use Silo\WhmcsModule\HomeStore;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity;
use Silo\WhmcsModule\ServerRegistry;
use Silo\WhmcsModule\Whmcs\CustomFieldStore;

/**
 * Builds a HookContext wired to the FakeClient but with the *real*
 * Identity, AttributeMapper, CustomFieldStore, ServerRegistry and
 * HomeStore — so handler tests exercise the genuine
 * resolution/mapping/custom-field/re-home code, with only the HTTP
 * boundary and WHMCS runtime faked.
 */
final class Context
{
    public static function make(
        FakeClient $client,
        ?ServerRegistry $servers = null,
        ?HomeStore $home = null,
    ): HookContext {
        return new HookContext(
            $client,
            new Identity($client),
            new AttributeMapper(),
            new CustomFieldStore(),
            $servers ?? new ServerRegistry(),
            $home ?? new HomeStore(),
        );
    }

    /**
     * A ServerRegistry whose per-server client is resolved from a map
     * keyed by the server's API key (tblservers.password, decrypted by
     * the identity shim). Lets re-home tests bind a distinct FakeClient
     * per Silo server.
     *
     * @param array<string, ClientInterface> $clientsByApiKey
     */
    public static function serverRegistry(array $clientsByApiKey): ServerRegistry
    {
        return new ServerRegistry(
            static fn(ServerConfig $cfg): ClientInterface =>
                $clientsByApiKey[$cfg->apiKey()] ?? new FakeClient()
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
            'serverid' => 1,
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
