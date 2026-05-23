<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\AttributeMapper;
use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class AttributeMapperTest extends TestCase
{
    /** @param array<string, mixed> $params @param array<int, array{name:string,value:string}> $opts */
    private function map(array $params, array $opts = []): array
    {
        return (new AttributeMapper())->apply(ProductConfig::fromParams($params), $opts);
    }

    public function testDefaultsAndEmptyLibrariesBecomeNull(): void
    {
        $attrs = $this->map([]);

        self::assertSame('user', $attrs['role'], 'role is fixed to user, not configurable');
        self::assertNull($attrs['library_ids'], 'no libraries listed → null = ALL');
        self::assertSame(6, $attrs['max_streams']);
        self::assertSame(2, $attrs['max_transcodes']);
        self::assertSame(5, $attrs['max_profiles']);
        self::assertTrue($attrs['download_allowed']);
        self::assertFalse($attrs['download_transcode_allowed']);
    }

    public function testProductLibraryIdsAreParsed(): void
    {
        $attrs = $this->map(['configoption1' => '1, 3 ,5']);
        self::assertSame([1, 3, 5], $attrs['library_ids']);
    }

    public function testExtraStreamsConfigurableOptionIsAdditive(): void
    {
        $attrs = $this->map([], [['name' => 'Extra Streams', 'value' => '3']]);
        self::assertSame(9, $attrs['max_streams']); // 6 default + 3
    }

    public function testMaxProfilesIsClampedToAtLeastOne(): void
    {
        $attrs = $this->map([], [['name' => 'Max Profiles', 'value' => '0']]);
        self::assertSame(1, $attrs['max_profiles']);
    }

    public function testTranscodeDownloadsForcedOffWhenDownloadsOff(): void
    {
        $attrs = $this->map([
            'configoption5' => 'no',  // downloads off
            'configoption6' => 'yes', // transcode-downloads on (should be neutralised)
        ]);
        self::assertFalse($attrs['download_allowed']);
        self::assertFalse($attrs['download_transcode_allowed']);
    }

    public function testFourKConfigurableOptionUpgradesQuality(): void
    {
        $attrs = $this->map([], [['name' => '4k streaming', 'value' => 'yes']]);
        self::assertSame('4k', $attrs['max_playback_quality']);
    }

    public function testPerLibraryToggleAppendsLibraryId(): void
    {
        $attrs = $this->map(['configoption1' => '1'], [['name' => 'Library 5', 'value' => 'yes']]);
        self::assertSame([1, 5], $attrs['library_ids']);
    }
}
