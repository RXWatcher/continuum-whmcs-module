<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\Handler\SetEnabled;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\TestCase;

/**
 * SetEnabled backs Suspend (false) and Unsuspend (true). It must always
 * send an explicit `enabled` flag, since Silo's updateUser is a
 * partial PATCH.
 */
final class SetEnabledTest extends TestCase
{
    private function resolved(): FakeClient
    {
        $client = new FakeClient();
        $client->usersById[70] = ['id' => 70];
        return $client;
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        return Context::params(['customfields' => ['silo_user_id' => '70']]);
    }

    public function testUnsuspendSendsEnabledTrue(): void
    {
        $client = $this->resolved();

        $result = (new SetEnabled(Context::make($client)))->handle($this->params(), true);

        self::assertSame('success', $result);
        self::assertTrue($client->lastUpdateUserPayload()['enabled']);
    }

    public function testSuspendSendsEnabledFalse(): void
    {
        $client = $this->resolved();

        $result = (new SetEnabled(Context::make($client)))->handle($this->params(), false);

        self::assertSame('success', $result);
        self::assertFalse($client->lastUpdateUserPayload()['enabled']);
    }

    public function testUnresolvedUserReturnsGuidance(): void
    {
        $client = new FakeClient();

        $result = (new SetEnabled(Context::make($client)))->handle(Context::params(), true);

        self::assertStringContainsString('No Silo user is linked', $result);
        self::assertFalse($client->called('updateUser'));
    }
}
