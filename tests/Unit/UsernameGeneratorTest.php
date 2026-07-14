<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\UsernameGenerator;
use Silo\WhmcsModule\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('nameCases')]
    public function testGeneratesNameBasedUsername(
        string $first,
        string $last,
        ?string $expected
    ): void {
        self::assertSame(
            $expected,
            UsernameGenerator::generateFromName($first, $last, static fn(): int => 7)
        );
    }

    public static function nameCases(): array
    {
        return [
            'standard' => ['Jim', 'Cole', 'jcole007'],
            'surname truncated' => ['Sarah', 'Smith', 'ssmit007'],
            'short surname' => ['Amy', 'Li', 'ali007'],
            'apostrophe' => ['David', "O'Connor", 'docon007'],
            'compound' => ['Emma', 'Van Dijk', 'evand007'],
            'hyphen' => ['Anne-Marie', 'Smith-Jones', 'asmit007'],
            'whitespace and case' => ['  JIM ', ' cOLE  ', 'jcole007'],
            'missing first' => ['', 'Cole', null],
            'missing last' => ['Jim', '', null],
            'non-latin unusable' => ['李', '王', null],
        ];
    }

    #[DataProvider('invalidSuffixes')]
    public function testRejectsSuffixOutsideThreeDigitRange(int $suffix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UsernameGenerator::generateFromName('Jim', 'Cole', static fn(): int => $suffix);
    }

    public static function invalidSuffixes(): array
    {
        return [
            'below range' => [-1],
            'above range' => [1000],
        ];
    }
}
