<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $pc = ProductConfig::fromParams([]);

        self::assertSame([], $pc->libraryIds());
        self::assertSame(6, $pc->maxStreams());
        self::assertSame(2, $pc->maxTranscodes());
        self::assertSame(5, $pc->maxProfiles());
        self::assertTrue($pc->downloadAllowed());
        self::assertFalse($pc->downloadTranscodeAllowed());
        self::assertSame('', $pc->maxPlaybackQuality());
        self::assertTrue($pc->createDefaultProfile());
        self::assertFalse($pc->allowUserChosenUsername());
    }

    public function testLibraryIdsParsing(): void
    {
        self::assertSame(
            [1, 3, 5],
            ProductConfig::fromParams(['configoption1' => ' 1, 3 ,5 '])->libraryIds()
        );

        $this->expectException(\InvalidArgumentException::class);
        ProductConfig::fromParams(['configoption1' => '1,abc']);
    }

    public function testNonIntegerLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductConfig::fromParams(['configoption2' => 'lots']);
    }

    #[DataProvider('yesNoCases')]
    public function testYesNoParsing(string $raw, bool $expected): void
    {
        self::assertSame(
            $expected,
            ProductConfig::fromParams(['configoption5' => $raw])->downloadAllowed()
        );
    }

    public static function yesNoCases(): array
    {
        return [
            ['yes', true],
            ['on', true],
            ['1', true],
            ['no', false],
            ['0', false],
            ['', true], // empty → default (downloads default is true)
        ];
    }

    public function testDeleteOnTerminateDefaultsOn(): void
    {
        self::assertTrue(ProductConfig::deleteOnTerminate([]));
        self::assertTrue(ProductConfig::deleteOnTerminate(['configoption10' => 'on']));
        self::assertFalse(ProductConfig::deleteOnTerminate(['configoption10' => 'no']));
    }

    public function testAutoRehomeDefaultsOff(): void
    {
        self::assertFalse(ProductConfig::autoRehome([]));
        self::assertTrue(ProductConfig::autoRehome(['configoption11' => 'on']));
    }

    public function testPlaybackQualityIsCanonicalised(): void
    {
        self::assertSame('1080p', ProductConfig::fromParams(['configoption7' => '720p'])->maxPlaybackQuality());
        self::assertSame('4k', ProductConfig::fromParams(['configoption7' => '2160p'])->maxPlaybackQuality());
        self::assertSame('', ProductConfig::fromParams(['configoption7' => ''])->maxPlaybackQuality());
    }
}
