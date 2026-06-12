<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\BadWordList;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class BadWordListTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/silo_bw_' . uniqid('', true);
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

    public function testContainsMatchesBadWordAsSubstring(): void
    {
        $path = $this->writeFile('w.txt', "fuck\n");
        $list = BadWordList::fromFile($path);

        self::assertTrue($list->contains('fuck'), 'exact match still works');
        self::assertTrue($list->contains('fuckers'), 'embedded bad word is caught');
        self::assertTrue($list->contains('ASSFUCK'), 'case-insensitive substring');
        self::assertFalse($list->contains('clean'));
    }

    public function testEmptyListContainsNothing(): void
    {
        $list = BadWordList::fromFile($this->dir . '/nope.txt');
        self::assertFalse($list->contains('anything'));
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
