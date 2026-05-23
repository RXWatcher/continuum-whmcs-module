<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\PlaybackQuality;
use Silo\WhmcsModule\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlaybackQualityTest extends TestCase
{
    #[DataProvider('canonicalCases')]
    public function testCanonical(string $input, string $expected): void
    {
        self::assertSame($expected, PlaybackQuality::canonical($input));
    }

    public static function canonicalCases(): array
    {
        return [
            '4k'            => ['4k', '4k'],
            'uhd'           => ['UHD', '4k'],
            '2160p'         => ['2160p', '4k'],
            '4320p'         => ['4320', '4k'],
            '1080p'         => ['1080p', '1080p'],
            'fhd'           => ['FHD', '1080p'],
            'legacy 720p'   => ['720p', '1080p'],
            'legacy 480p'   => ['480', '1080p'],
            'spaced/dashed' => [' 1080 P ', '1080p'],
            'empty'         => ['', ''],
            'unrestricted'  => ['unrestricted', ''],
            'unknown'       => ['banana', ''],
        ];
    }

    public function testHuman(): void
    {
        self::assertSame('Up to 4K', PlaybackQuality::human('2160p'));
        self::assertSame('Up to 1080p', PlaybackQuality::human('720p'));
        self::assertSame('Unrestricted', PlaybackQuality::human(''));
    }
}
