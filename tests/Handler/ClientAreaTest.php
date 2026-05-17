<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\ClientArea;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class ClientAreaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function params(array $cf = ['continuum_user_id' => '5']): array
    {
        return Context::params(['customfields' => $cf]);
    }

    public function testUnlinkedServiceShowsContactSupport(): void
    {
        $out = (new ClientArea(Context::make(new FakeClient())))
            ->handle(Context::params(['username' => '', 'clientsdetails' => ['email' => '']]));

        self::assertSame('clientarea', $out['templatefile']);
        self::assertStringContainsString('not yet linked', $out['vars']['error']);
    }

    public function testActiveUserWithUnrestrictedLibraries(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = [
            'id' => 5,
            'max_streams' => 4,
            'max_playback_quality' => '2160p',
            'enabled' => true,
            'library_ids' => null, // unrestricted
        ];

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        self::assertSame('active', $vars['status']);
        self::assertSame(4, $vars['stream_limit']);
        self::assertSame('Up to 4K', $vars['quality']);
        self::assertSame(['All libraries'], $vars['library_names']);
        self::assertSame('https://continuum.test/', $vars['login_url']);
    }

    public function testDisabledUserShowsSuspended(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5, 'enabled' => false, 'library_ids' => []];

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        self::assertSame('suspended', $vars['status']);
    }

    public function testGetUserFailureDegradesGracefully(): void
    {
        // Resolve via email (tier 2, no getUser) so the handler's own
        // getUser call is what fails.
        $client = new FakeClient();
        $client->usersByEmail['jane@example.com'] = ['id' => 5];
        $client->getUserError = new ContinuumApiException('down', 503);

        $vars = (new ClientArea(Context::make($client)))->handle(Context::params())['vars'];

        self::assertSame('active (status unavailable)', $vars['status']);
    }

    public function testLibraryNamesServedFromFreshCache(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5, 'library_ids' => [1, 2]];
        $cache = json_encode([
            'cached_at' => time(),
            'names' => [1 => 'Movies', 2 => 'TV', 3 => 'Music'],
        ]);

        $vars = (new ClientArea(Context::make($client)))->handle($this->params([
            'continuum_user_id' => '5',
            'continuum_library_names_cache' => $cache,
        ]))['vars'];

        self::assertSame(['Movies', 'TV'], $vars['library_names']);
        self::assertFalse($client->called('listLibraries'), 'fresh cache must avoid the API');
    }

    public function testLibraryNamesResolvedViaApiOnCacheMiss(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5, 'library_ids' => [2]];
        $client->libraries = [
            ['id' => 1, 'name' => 'Movies'],
            ['id' => 2, 'name' => 'TV'],
        ];

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        self::assertSame(['TV'], $vars['library_names']);
        self::assertTrue($client->called('listLibraries'));
    }
}
