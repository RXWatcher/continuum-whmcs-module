<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\PasswordResetThrottle;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class PasswordResetThrottleTest extends TestCase
{
    public function testFreshServiceIsNotBlocked(): void
    {
        self::assertSame(0, (new PasswordResetThrottle())->blockedFor(7, 60));
    }

    public function testRecordThenBlockedWithinCooldown(): void
    {
        $throttle = new PasswordResetThrottle();
        $throttle->record(7);

        self::assertGreaterThan(0, $throttle->blockedFor(7, 60));
        self::assertSame(0, $throttle->blockedFor(8, 60), 'unrelated service is independent');
    }

    public function testOldStampDoesNotBlock(): void
    {
        FakeWhmcs::seedTable('mod_silo_pw_reset', [
            ['relid' => 7, 'last_reset_at' => time() - 3600],
        ]);
        self::assertSame(0, (new PasswordResetThrottle())->blockedFor(7, 60));
    }

    public function testZeroCooldownDisablesThrottle(): void
    {
        FakeWhmcs::seedTable('mod_silo_pw_reset', [
            ['relid' => 7, 'last_reset_at' => time()],
        ]);
        self::assertSame(0, (new PasswordResetThrottle())->blockedFor(7, 0));
    }

    public function testRecordUpdatesInPlaceWithoutDuplicating(): void
    {
        $throttle = new PasswordResetThrottle();
        $throttle->record(7);
        $throttle->record(7);

        self::assertCount(1, FakeWhmcs::rows('mod_silo_pw_reset'));
    }

    public function testZeroServiceIdIsNeverBlocked(): void
    {
        $throttle = new PasswordResetThrottle();
        $throttle->record(0);
        self::assertSame(0, $throttle->blockedFor(0, 60));
        self::assertSame([], FakeWhmcs::rows('mod_silo_pw_reset'));
    }
}
