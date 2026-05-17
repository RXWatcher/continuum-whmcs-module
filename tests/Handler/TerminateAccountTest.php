<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\TerminateAccount;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class TerminateAccountTest extends TestCase
{
    /** @return array<string, mixed> */
    private function params(array $overrides = []): array
    {
        return Context::params(array_replace_recursive(
            ['customfields' => ['continuum_user_id' => '60']],
            $overrides
        ));
    }

    public function testDeleteOnTerminateDefaultOnDeletesUser(): void
    {
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60];

        // configoption10 unset → deleteOnTerminate defaults ON.
        $result = (new TerminateAccount(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($client->called('deleteUser'));
        self::assertFalse($client->called('updateUser'));
    }

    public function testDeleteOnTerminateOffOnlyDisablesUser(): void
    {
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60];

        $result = (new TerminateAccount(Context::make($client)))
            ->handle($this->params(['configoption10' => 'no']));

        self::assertSame('success', $result);
        self::assertFalse($client->called('deleteUser'));
        self::assertFalse($client->lastUpdateUserPayload()['enabled']);
    }

    public function testNoLinkedUserStillSucceeds(): void
    {
        $client = new FakeClient(); // nothing resolvable

        $result = (new TerminateAccount(Context::make($client)))
            ->handle(Context::params());

        self::assertSame('success', $result);
        self::assertFalse($client->called('deleteUser'));
    }

    public function testAlreadyGoneUserTreatedAsSuccess(): void
    {
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60];
        $client->deleteUserError = new ContinuumApiException('gone', 404);

        $result = (new TerminateAccount(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);
    }
}
