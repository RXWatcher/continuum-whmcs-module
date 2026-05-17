<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\BadWordList;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class BadWordListTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/continuum_bw_' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    public function testFromFileSkipsCommentsAndBlanksAndCaseFolds(): void
    {
        $path = $this->writeFile('w.txt', "naughty\n# a comment\n\n  Spaced  \nUPPER\n");
        $list = BadWordList::fromFile($path);

        self::assertSame(['naughty', 'spaced', 'upper'], $list->entries());
        self::assertTrue($list->contains('NAUGHTY'));   // case-insensitive
        self::assertTrue($list->contains('spaced'));
        self::assertFalse($list->contains('# a comment'));
        self::assertFalse($list->contains('clean'));
    }

    public function testMissingFileYieldsEmptyList(): void
    {
        self::assertSame([], BadWordList::fromFile($this->dir . '/nope.txt')->entries());
    }

    public function testDefaultListLoads(): void
    {
        $default = BadWordList::default();
        self::assertNotEmpty($default->entries());
        self::assertTrue($default->contains('fuck'), 'documented first entry of the shipped list');
    }

    public function testResolvePrefersSiblingOverrideAndReplacesDefault(): void
    {
        // No override → falls back to the shipped default list.
        self::assertTrue(BadWordList::resolve($this->dir)->contains('fuck'));

        // Override present → replaces (not merges) the default.
        $this->writeFile('bad_words.txt', "onlythis\n");
        $resolved = BadWordList::resolve($this->dir);
        self::assertTrue($resolved->contains('onlythis'));
        self::assertFalse($resolved->contains('fuck'), 'override replaces, never merges');
    }
}
