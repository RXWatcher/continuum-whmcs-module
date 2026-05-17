<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\Identity\Params;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class ParamsTest extends TestCase
{
    public function testEmailIsLoweredAndTrimmed(): void
    {
        self::assertSame(
            'jane@ex.com',
            Params::email(['clientsdetails' => ['email' => '  Jane@Ex.com ']])
        );
        self::assertSame('', Params::email([]));
    }

    public function testServiceIdAndPassword(): void
    {
        self::assertSame(7, Params::serviceId(['serviceid' => '7']));
        self::assertSame(0, Params::serviceId([]));
        self::assertSame('pw', Params::password(['password' => 'pw']));
        self::assertSame('', Params::password([]));
    }

    public function testContinuumUserIdRequiresDigits(): void
    {
        self::assertSame(42, Params::continuumUserId(['customfields' => ['continuum_user_id' => ' 42 ']]));
        self::assertNull(Params::continuumUserId(['customfields' => ['continuum_user_id' => 'x']]));
        self::assertNull(Params::continuumUserId(['customfields' => []]));
    }

    public function testDesiredUsernameExactKey(): void
    {
        self::assertSame(
            'bob',
            Params::desiredUsername(['customfields' => ['desired_username' => '  bob ']])
        );
    }

    public function testDesiredUsernameResolvesPostPipeLabel(): void
    {
        // WHMCS keys the field by the text AFTER the `|`, so the module
        // must still recognise it as the desired-username field.
        self::assertSame(
            'cooluser',
            Params::desiredUsername(['customfields' => ['Enter your desired username' => 'cooluser']])
        );
    }

    public function testDesiredUsernameNormalisesSeparators(): void
    {
        self::assertSame(
            'handle',
            Params::desiredUsername(['customfields' => ['desired-username' => 'handle']])
        );
    }

    public function testDesiredUsernameAbsentIsEmptyString(): void
    {
        self::assertSame('', Params::desiredUsername(['customfields' => ['something_else' => 'x']]));
        self::assertSame('', Params::desiredUsername([]));
    }
}
