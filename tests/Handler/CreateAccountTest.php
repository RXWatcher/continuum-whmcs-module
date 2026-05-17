<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\CreateAccount;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class CreateAccountTest extends TestCase
{
    private function duplicate(): ContinuumApiException
    {
        return new ContinuumApiException('dup', 409, ['error' => 'duplicate_username']);
    }

    /**
     * Regression for the retain-on-terminate bug: when CreateAccount
     * resolves an EXISTING (possibly disabled) user, the update must
     * assert enabled=true so a re-order re-enables a previously
     * terminate-disabled customer.
     */
    public function testExistingUserIsReEnabledOnReorder(): void
    {
        $client = new FakeClient();
        $client->usersById[42] = ['id' => 42];
        $params = Context::params(['customfields' => ['continuum_user_id' => '42']]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertSame('success', $result);
        self::assertFalse($client->called('createUser'), 'must update, not create');
        $payload = $client->lastUpdateUserPayload();
        self::assertNotNull($payload);
        self::assertTrue($payload['enabled'], 'enabled must be asserted true');
    }

    public function testNewUserGetsGeneratedUsernameAndLinkageIsWrittenBack(): void
    {
        $client = new FakeClient();
        $client->createUserQueue[] = ['id' => 100];

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertSame('success', $result);
        $created = $client->lastCreateUserPayload();
        self::assertNotNull($created);
        self::assertSame('jane@example.com', $created['email']);
        self::assertNotSame('', (string)$created['username']);
        self::assertTrue($created['create_default_profile']);

        // serviceusername written back to WHMCS via UpdateClientProduct.
        $usernames = array_filter(
            FakeWhmcs::$updateClientProduct,
            static fn($p) => isset($p['serviceusername'])
        );
        self::assertNotEmpty($usernames);
        self::assertSame(
            $created['username'],
            array_values($usernames)[0]['serviceusername']
        );
    }

    public function testGeneratedUsernameCollisionRetriesThenSucceeds(): void
    {
        $client = new FakeClient();
        $client->createUserQueue = [$this->duplicate(), $this->duplicate(), ['id' => 102]];

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertSame('success', $result);
        self::assertSame(3, $client->countCalls('createUser'));
    }

    public function testGeneratedUsernameCongestionGivesUpAfterFive(): void
    {
        $client = new FakeClient();
        $client->createUserQueue = array_fill(0, 5, $this->duplicate());

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertStringContainsString('congested', $result);
        self::assertSame(5, $client->countCalls('createUser'));
    }

    public function testChosenUsernameRejectedWhenAlreadyTaken(): void
    {
        $client = new FakeClient();
        $client->usersByUsername['cooluser'] = ['id' => 9]; // desired handle taken
        $params = Context::params([
            'configoption9' => 'on', // allow customer-chosen username
            'customfields' => ['desired_username' => 'cooluser'],
        ]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertStringContainsString('already taken', $result);
        self::assertFalse($client->called('createUser'));
    }

    public function testChosenUsernameIsUsedVerbatimWhenFree(): void
    {
        $client = new FakeClient();
        $client->createUserQueue[] = ['id' => 101];
        $params = Context::params([
            'configoption9' => 'on',
            'customfields' => ['desired_username' => 'cooluser'],
        ]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertSame('success', $result);
        self::assertSame('cooluser', $client->lastCreateUserPayload()['username']);
    }

    public function testMissingClientEmailIsRejected(): void
    {
        $client = new FakeClient();
        $params = Context::params([
            'serviceid' => 0, // skip custom-field probe / provisioner
            'clientsdetails' => ['firstname' => 'Jane', 'email' => ''],
        ]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertSame('Client email is required', $result);
        self::assertFalse($client->called('createUser'));
    }
}
