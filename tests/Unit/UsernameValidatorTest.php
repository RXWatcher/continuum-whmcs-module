<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\BadWordList;
use Continuum\WhmcsModule\UsernameValidator;
use Continuum\WhmcsModule\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UsernameValidatorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/continuum_uv_' . uniqid('', true) . '.txt';
        file_put_contents($this->file, "naughty\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function validator(array $extraReserved = []): UsernameValidator
    {
        return new UsernameValidator(BadWordList::fromFile($this->file), $extraReserved);
    }

    public function testAcceptsWellFormedHandle(): void
    {
        self::assertNull($this->validator()->validate('bob_1'));
        self::assertNull($this->validator()->validate('a-b-c'));
        self::assertNull($this->validator()->validate('abc'));            // min length
        self::assertNull($this->validator()->validate('abcdefghijkl'));   // max length 12
    }

    #[DataProvider('badFormats')]
    public function testRejectsBadFormat(string $candidate): void
    {
        self::assertSame(
            'Username must be 3-12 lowercase letters, digits, underscores, or hyphens.',
            $this->validator()->validate($candidate)
        );
    }

    public static function badFormats(): array
    {
        return [
            'too short'      => ['ab'],
            'too long'       => ['abcdefghijklm'], // 13
            'uppercase'      => ['Bob1'],
            'space'          => ['bo b'],
            'symbol'         => ['bob!'],
            'empty'          => [''],
        ];
    }

    public function testReservedBuiltin(): void
    {
        self::assertSame('That username is reserved.', $this->validator()->validate('admin'));
        self::assertSame('That username is reserved.', $this->validator()->validate('continuum'));
    }

    public function testReservedExtra(): void
    {
        self::assertSame(
            'That username is reserved.',
            $this->validator(['VIPName'])->validate('vipname')
        );
    }

    public function testProfanityRejected(): void
    {
        self::assertSame("That username isn't allowed.", $this->validator()->validate('naughty'));
    }

    public function testFormatIsCheckedBeforeReserved(): void
    {
        // 'Admin' is reserved but also fails format (uppercase); the
        // format message must win to keep the precedence stable.
        self::assertSame(
            'Username must be 3-12 lowercase letters, digits, underscores, or hyphens.',
            $this->validator()->validate('Admin')
        );
    }
}
