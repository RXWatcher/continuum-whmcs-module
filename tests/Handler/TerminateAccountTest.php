<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\TerminateAccount;
use Continuum\WhmcsModule\HomeStore;
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

    public function testDeleteOnTerminateDropsHomeStorePointer(): void
    {
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60];
        $home = new HomeStore();
        $home->put('jane@example.com', 1, 60);

        (new TerminateAccount(Context::make($client, null, $home)))->handle($this->params());

        self::assertNull(
            $home->get('jane@example.com'),
            'a permanently-deleted user has no home to re-home to'
        );
    }

    public function testRetainOnTerminateKeepsHomeStorePointer(): void
    {
        // delete_on_terminate=OFF retains the user (just disables it), so
        // the pointer must stay — a re-order on the same email should
        // still find its way back to the right server.
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60];
        $home = new HomeStore();
        $home->put('jane@example.com', 1, 60);

        (new TerminateAccount(Context::make($client, null, $home)))
            ->handle($this->params(['configoption10' => 'no']));

        self::assertSame(
            ['serverid' => 1, 'userid' => 60],
            $home->get('jane@example.com')
        );
    }
}
