<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Handler\TerminateAccount;
use Silo\WhmcsModule\HomeStore;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class TerminateAccountTest extends TestCase
{
    /** @return array<string, mixed> */
    private function params(array $overrides = []): array
    {
        return Context::params(array_replace_recursive(
            ['customfields' => ['silo_user_id' => '60']],
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

    public function testDoesNotDeleteUserSharedByAnotherActiveService(): void
    {
        // Two WHMCS services for the same customer resolve (by email/id)
        // to ONE Silo user. Terminating one with delete ON must NOT
        // destroy the account the other still-active service depends on —
        // it disables instead.
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60, 'email' => 'jane@example.com'];

        FakeWhmcs::seedTable('tblcustomfields', [
            ['id' => 10, 'type' => 'product', 'fieldname' => 'silo_user_id', 'relid' => 3],
        ]);
        FakeWhmcs::seedTable('tblcustomfieldsvalues', [
            ['fieldid' => 10, 'relid' => 7, 'value' => '60'], // the one being terminated
            ['fieldid' => 10, 'relid' => 8, 'value' => '60'], // a sibling service
        ]);
        FakeWhmcs::seedTable('tblhosting', [
            ['id' => 8, 'domainstatus' => 'Active'],
        ]);

        $result = (new TerminateAccount(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);
        self::assertFalse($client->called('deleteUser'), 'shared user must survive');
        self::assertFalse($client->lastUpdateUserPayload()['enabled'], 'disabled instead of deleted');
    }

    public function testDeletesWhenSiblingServiceIsNotActive(): void
    {
        // A cancelled/terminated sibling does not keep the user alive.
        $client = new FakeClient();
        $client->usersById[60] = ['id' => 60, 'email' => 'jane@example.com'];

        FakeWhmcs::seedTable('tblcustomfields', [
            ['id' => 10, 'type' => 'product', 'fieldname' => 'silo_user_id', 'relid' => 3],
        ]);
        FakeWhmcs::seedTable('tblcustomfieldsvalues', [
            ['fieldid' => 10, 'relid' => 7, 'value' => '60'],
            ['fieldid' => 10, 'relid' => 8, 'value' => '60'],
        ]);
        FakeWhmcs::seedTable('tblhosting', [
            ['id' => 8, 'domainstatus' => 'Terminated'],
        ]);

        $result = (new TerminateAccount(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($client->called('deleteUser'));
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
        $client->deleteUserError = new SiloApiException('gone', 404);

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
