<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $pc = ProductConfig::fromParams([]);

        self::assertSame('user', $pc->role());
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

    public function testRoleValidation(): void
    {
        self::assertSame('admin', ProductConfig::fromParams(['configoption1' => 'admin'])->role());
        self::assertSame('user', ProductConfig::fromParams(['configoption1' => ''])->role());

        $this->expectException(\InvalidArgumentException::class);
        ProductConfig::fromParams(['configoption1' => 'superuser']);
    }

    public function testLibraryIdsParsing(): void
    {
        self::assertSame(
            [1, 3, 5],
            ProductConfig::fromParams(['configoption2' => ' 1, 3 ,5 '])->libraryIds()
        );

        $this->expectException(\InvalidArgumentException::class);
        ProductConfig::fromParams(['configoption2' => '1,abc']);
    }

    public function testNonIntegerLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductConfig::fromParams(['configoption3' => 'lots']);
    }

    #[DataProvider('yesNoCases')]
    public function testYesNoParsing(string $raw, bool $expected): void
    {
        self::assertSame(
            $expected,
            ProductConfig::fromParams(['configoption6' => $raw])->downloadAllowed()
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
            ['', true], // empty → default (configoption6 default is true)
        ];
    }

    public function testDeleteOnTerminateDefaultsOn(): void
    {
        self::assertTrue(ProductConfig::deleteOnTerminate([]));
        self::assertTrue(ProductConfig::deleteOnTerminate(['configoption11' => 'on']));
        self::assertFalse(ProductConfig::deleteOnTerminate(['configoption11' => 'no']));
    }

    public function testPlaybackQualityIsCanonicalised(): void
    {
        self::assertSame('1080p', ProductConfig::fromParams(['configoption8' => '720p'])->maxPlaybackQuality());
        self::assertSame('4k', ProductConfig::fromParams(['configoption8' => '2160p'])->maxPlaybackQuality());
        self::assertSame('', ProductConfig::fromParams(['configoption8' => ''])->maxPlaybackQuality());
    }
}
