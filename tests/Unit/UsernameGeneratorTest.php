<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\UsernameGenerator;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class UsernameGeneratorTest extends TestCase
{
    public function testFormatIsFourLettersThenThreeDigits(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $u = UsernameGenerator::generate();
            self::assertSame(7, strlen($u));
            self::assertMatchesRegularExpression('/^[a-z]{4}[0-9]{3}$/', $u, "bad output: {$u}");
        }
    }

    public function testProducesVariety(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[UsernameGenerator::generate()] = true;
        }
        // Collisions in 50 draws over a ~457M namespace are vanishingly
        // unlikely; this would catch a constant/broken generator.
        self::assertGreaterThan(40, count($seen));
    }
}
