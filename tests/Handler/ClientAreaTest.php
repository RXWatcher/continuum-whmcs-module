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

    public function testRichStatusSurfacesUsernamePlanProfilesAndStreams(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = [
            'id' => 5,
            'username' => 'abcd232',
            'enabled' => true,
            'max_streams' => 6,
            'max_transcodes' => 2,
            'max_profiles' => 5,
            'max_playback_quality' => '1080p',
            'download_allowed' => true,
            'created_at' => '2026-01-15T10:00:00Z',
            'library_ids' => null,
        ];
        $client->userProfiles = [
            ['id' => 'p1', 'name' => 'Jane'],
            ['id' => 'p2', 'name' => 'Kids'],
        ];
        $client->sessions = [
            ['user_id' => 5, 'media_title' => 'The Matrix', 'media_type' => 'movie'],
            ['user_id' => 5, 'series_name' => 'The Office', 'season_number' => 3,
             'episode_number' => 7, 'episode_name' => 'Branch Closing', 'is_paused' => true],
            ['user_id' => 99, 'media_title' => 'Someone Else'], // other customer — excluded
        ];

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        self::assertSame('abcd232', $vars['username']);
        self::assertSame(2, $vars['transcode_limit']);
        self::assertSame(5, $vars['profile_limit']);
        self::assertSame('Allowed', $vars['downloads']);
        self::assertSame('Jan 15, 2026', $vars['member_since']);
        self::assertSame(2, $vars['profiles_used']);
        self::assertSame(['Jane', 'Kids'], $vars['profile_names']);
        self::assertSame(2, $vars['active_streams'], 'other customers\' sessions excluded');
        self::assertSame(
            ['The Matrix', 'The Office — S3E7 · Branch Closing (paused)'],
            $vars['now_watching']
        );
    }

    public function testProfilesAndSessionsDegradeIndependently(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5, 'enabled' => true, 'library_ids' => []];
        $client->listUserProfilesError = new ContinuumApiException('profiles down', 503);
        $client->listSessionsError = new ContinuumApiException('sessions down', 503);

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        // Page still renders; the two failed enrichments are simply absent.
        self::assertSame('active', $vars['status']);
        self::assertNull($vars['profiles_used']);
        self::assertNull($vars['active_streams']);
        self::assertSame([], $vars['now_watching']);
    }

    public function testNoSessionsLeavesEmptyWatchingButZeroCount(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5, 'enabled' => true, 'library_ids' => []];

        $vars = (new ClientArea(Context::make($client)))->handle($this->params())['vars'];

        self::assertSame(0, $vars['active_streams']);
        self::assertSame([], $vars['now_watching']);
    }
}
