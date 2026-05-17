<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\Handler\SetEnabled;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

/**
 * SetEnabled backs Suspend (false) and Unsuspend (true). It must always
 * send an explicit `enabled` flag, since Continuum's updateUser is a
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
        return Context::params(['customfields' => ['continuum_user_id' => '70']]);
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

        self::assertStringContainsString('No Continuum user is linked', $result);
        self::assertFalse($client->called('updateUser'));
    }
}
