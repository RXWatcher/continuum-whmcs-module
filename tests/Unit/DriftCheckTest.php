<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\DriftCheck;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class DriftCheckTest extends TestCase
{
    public function testInSyncYieldsNoDrift(): void
    {
        $state = [
            'enabled' => true,
            'role' => 'user',
            'library_ids' => [1, 2],
            'max_streams' => 6,
            'download_allowed' => true,
            'max_playback_quality' => '1080p',
        ];
        self::assertSame([], DriftCheck::compare(7, 50, $state, $state));
    }

    public function testEnabledDriftReported(): void
    {
        $d = DriftCheck::compare(7, 50, ['enabled' => true], ['enabled' => false]);
        self::assertCount(1, $d);
        self::assertStringContainsString('enabled expected=true', $d[0]);
        self::assertStringContainsString('service 7 → user 50', $d[0]);
    }

    public function testRoleDriftReported(): void
    {
        $d = DriftCheck::compare(1, 2, ['role' => 'admin'], ['role' => 'user']);
        self::assertCount(1, $d);
        self::assertStringContainsString('role expected=admin', $d[0]);
    }

    public function testLibraryIdsAreOrderInsensitive(): void
    {
        self::assertSame(
            [],
            DriftCheck::compare(1, 2, ['library_ids' => [3, 1]], ['library_ids' => [1, 3]])
        );
    }

    public function testLibraryIdsMismatchReported(): void
    {
        $d = DriftCheck::compare(1, 2, ['library_ids' => [1, 2]], ['library_ids' => [1]]);
        self::assertCount(1, $d);
        self::assertStringContainsString('library_ids expected=[1,2]', $d[0]);
    }

    public function testIntAndBoolFieldDrift(): void
    {
        $d = DriftCheck::compare(1, 2, [
            'max_streams' => 6,
            'download_allowed' => true,
        ], [
            'max_streams' => 4,
            'download_allowed' => false,
        ]);
        self::assertCount(2, $d);
        self::assertStringContainsString('max_streams expected=6', $d[0]);
        self::assertStringContainsString('download_allowed expected=true', $d[1]);
    }

    public function testPlaybackQualityComparedCanonically(): void
    {
        // Module says '4k', Continuum stores '2160p' — same thing.
        self::assertSame(
            [],
            DriftCheck::compare(1, 2, ['max_playback_quality' => '4k'], ['max_playback_quality' => '2160p'])
        );
        // 480p/720p both mean 1080p.
        self::assertSame(
            [],
            DriftCheck::compare(1, 2, ['max_playback_quality' => '1080p'], ['max_playback_quality' => '720p'])
        );
        $d = DriftCheck::compare(1, 2, ['max_playback_quality' => '4k'], ['max_playback_quality' => '1080p']);
        self::assertCount(1, $d);
        self::assertStringContainsString("max_playback_quality expected='4k'", $d[0]);
    }

    public function testOnlyExpectedKeysAreChecked(): void
    {
        // Observed has lots of fields; expected names only one → only that
        // one can drift.
        $d = DriftCheck::compare(1, 2, ['role' => 'user'], [
            'role' => 'user',
            'enabled' => false,
            'max_streams' => 999,
        ]);
        self::assertSame([], $d);
    }
}
