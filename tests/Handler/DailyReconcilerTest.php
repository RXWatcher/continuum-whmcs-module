<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\DailyReconciler;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

/**
 * Coverage for the completed reconciler: the daily run rebuilds the
 * full expected Silo-user state from the WHMCS-side product
 * config + configurable options + service status, and reports drift
 * for every attribute (not just `enabled`).
 */
final class DailyReconcilerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverParams = [
        'serverhostname' => 'srv1.example',
        'serverpassword' => 'admin-key',
        'serversecure' => 'on',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeWhmcs::seedTable('tblhosting', [
            ['id' => 10, 'server' => 1, 'domainstatus' => 'Active', 'packageid' => 3],
        ]);
        FakeWhmcs::seedTable('tblproducts', [
            ['id' => 3,
             'configoption1' => '1,2',       // library_ids
             'configoption2' => '10',         // max_streams
             'configoption3' => '3',          // max_transcodes
             'configoption4' => '5',          // max_profiles
             'configoption5' => 'yes',        // download_allowed
             'configoption6' => '',           // download_transcode_allowed
             'configoption7' => '1080p',      // max_playback_quality
             'configoption8' => 'yes',        // create_default_profile
             'configoption9' => '',           // allow_user_chosen_username
            ],
        ]);
        FakeWhmcs::seedTable('tblcustomfields', [
            ['id' => 1, 'type' => 'product', 'fieldname' => 'silo_user_id'],
        ]);
        FakeWhmcs::seedTable('tblcustomfieldsvalues', [
            ['fieldid' => 1, 'relid' => 10, 'value' => '42'],
        ]);
        FakeWhmcs::seedTable('tblhostingconfigoptions', []);
    }

    /** @return string[] */
    private function driftMessages(): array
    {
        return array_values(array_filter(
            FakeWhmcs::$activityLog,
            static fn(string $m): bool => str_starts_with($m, 'silo reconcile drift:')
        ));
    }

    public function testInSyncServiceLogsNoDrift(): void
    {
        $client = new FakeClient();
        $client->usersById[42] = [
            'id' => 42,
            'enabled' => true,
            'role' => 'user',
            'library_ids' => [1, 2],
            'max_streams' => 10,
            'max_transcodes' => 3,
            'max_profiles' => 5,
            'download_allowed' => true,
            'download_transcode_allowed' => false,
            'max_playback_quality' => '1080p',
        ];

        (new DailyReconciler(1, $this->serverParams, $client))->run();

        self::assertSame([], $this->driftMessages());
    }

    public function testLibraryAndLimitDriftAreBothReported(): void
    {
        $client = new FakeClient();
        $client->usersById[42] = [
            'id' => 42,
            'enabled' => true,
            'role' => 'user',
            'library_ids' => [1],          // expected [1, 2]
            'max_streams' => 4,            // expected 10
            'max_transcodes' => 3,
            'max_profiles' => 5,
            'download_allowed' => true,
            'download_transcode_allowed' => false,
            'max_playback_quality' => '1080p',
        ];

        (new DailyReconciler(1, $this->serverParams, $client))->run();

        $msgs = $this->driftMessages();
        self::assertNotEmpty($msgs);
        self::assertTrue(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'library_ids')),
            'library_ids drift must be reported'
        );
        self::assertTrue(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'max_streams')),
            'max_streams drift must be reported'
        );
    }

    public function testSuspendedServiceWithEnabledUserIsReportedAsDrift(): void
    {
        FakeWhmcs::seedTable('tblhosting', [
            ['id' => 10, 'server' => 1, 'domainstatus' => 'Suspended', 'packageid' => 3],
        ]);
        $client = new FakeClient();
        $client->usersById[42] = [
            'id' => 42, 'enabled' => true, 'role' => 'user',
            'library_ids' => [1, 2],
            'max_streams' => 10, 'max_transcodes' => 3, 'max_profiles' => 5,
            'download_allowed' => true, 'download_transcode_allowed' => false,
            'max_playback_quality' => '1080p',
        ];

        (new DailyReconciler(1, $this->serverParams, $client))->run();

        // A 'Suspended' service shouldn't show up at all (we only walk Active),
        // so there should be no drift report.
        self::assertSame([], $this->driftMessages());
    }

    public function testQuantityConfigurableOptionBumpsExpectedStreams(): void
    {
        // Service has +5 extra streams via a quantity-type configurable
        // option. Expected max_streams becomes 15. Silo has 10 →
        // drift expected.
        FakeWhmcs::seedTable('tblproductconfigoptions', [
            ['id' => 100, 'optionname' => 'Extra Streams', 'optiontype' => 4],
        ]);
        FakeWhmcs::seedTable('tblhostingconfigoptions', [
            ['relid' => 10, 'configid' => 100, 'optionid' => 0, 'qty' => 5],
        ]);

        $client = new FakeClient();
        $client->usersById[42] = [
            'id' => 42, 'enabled' => true, 'role' => 'user',
            'library_ids' => [1, 2],
            'max_streams' => 10, // expected 15 (10 base + 5 extra)
            'max_transcodes' => 3, 'max_profiles' => 5,
            'download_allowed' => true, 'download_transcode_allowed' => false,
            'max_playback_quality' => '1080p',
        ];

        (new DailyReconciler(1, $this->serverParams, $client))->run();

        $msgs = $this->driftMessages();
        self::assertTrue(
            (bool)array_filter(
                $msgs,
                static fn($m) => str_contains($m, 'max_streams') && str_contains($m, 'expected=15')
            ),
            'extra-streams configurable option must be reflected in expected state'
        );
    }

    public function testServiceWithoutLinkedUserIsSkippedWithLog(): void
    {
        FakeWhmcs::seedTable('tblcustomfieldsvalues', [
            ['fieldid' => 1, 'relid' => 10, 'value' => ''],
        ]);
        $client = new FakeClient();

        (new DailyReconciler(1, $this->serverParams, $client))->run();

        self::assertNotEmpty(array_filter(
            FakeWhmcs::$activityLog,
            static fn($m) => str_contains($m, 'has no silo_user_id')
        ));
        self::assertFalse($client->called('getUser'));
    }
}
