<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Handler\CreateAccount;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class CreateAccountTest extends TestCase
{
    private function duplicate(): SiloApiException
    {
        return new SiloApiException('dup', 409, ['error' => 'duplicate_username']);
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
        $params = Context::params(['customfields' => ['silo_user_id' => '42']]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertSame('success', $result);
        self::assertFalse($client->called('createUser'), 'must update, not create');
        $payload = $client->lastUpdateUserPayload();
        self::assertNotNull($payload);
        self::assertTrue($payload['enabled'], 'enabled must be asserted true');
    }

    public function testExistingUserReLinkSyncsServiceUsernameBackToWhmcs(): void
    {
        // On re-link, the Silo username is authoritative — write it back
        // to the WHMCS service so the admin/customer see a consistent
        // handle (the fresh-create and re-home paths already do this).
        $client = new FakeClient();
        $client->usersById[42] = ['id' => 42, 'username' => 'jhandle'];
        $client->updateUserResult = ['id' => 42, 'username' => 'jhandle'];
        $params = Context::params(['customfields' => ['silo_user_id' => '42']]);

        $result = (new CreateAccount(Context::make($client)))->handle($params);

        self::assertSame('success', $result);
        $usernames = array_filter(
            FakeWhmcs::$updateClientProduct,
            static fn($p) => isset($p['serviceusername'])
        );
        self::assertNotEmpty($usernames, 'service username must be written back on re-link');
        self::assertSame('jhandle', array_values($usernames)[0]['serviceusername']);
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

    public function testTransientCreateErrorRecoversByEmailInsteadOfDuplicating(): void
    {
        // createUser appears to fail (network blip / 5xx) but the user
        // was actually created on Silo's side — the response just
        // didn't reach us. Re-trying with a fresh generated username
        // would orphan the first account. Recovery: look up by email
        // after the failure and link to the recovered record.
        //
        // The anonymous subclass models "user materialises on the
        // Silo side as a side effect of the failed call".
        $client = new class extends FakeClient {
            public function createUser(array $payload): array
            {
                $this->usersByEmail[strtolower($payload['email'])] = [
                    'id' => 555,
                    'email' => $payload['email'],
                    'username' => 'recovered_handle',
                ];
                return parent::createUser($payload); // throws from queue
            }
        };
        $client->createUserQueue[] = new SiloApiException('network blip', 0);

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertSame('success', $result);
        self::assertSame(1, $client->countCalls('createUser'), 'no retry — recovered by email');
        // findUserByEmail is called twice: once by strict resolve (pre-create,
        // returns null) and once by recovery (post-create, returns the user).
        self::assertSame(2, $client->countCalls('findUserByEmail'));
    }

    public function testHardFailureOnCreateUserSurfacesError(): void
    {
        // A 4xx that is NOT a duplicate-username (e.g. 400 invalid input)
        // is the user's fault — no recovery, propagate cleanly. Confirms
        // recovery only catches network/5xx, not the whole exception bag.
        $client = new FakeClient();
        $client->createUserQueue[] = new SiloApiException('bad payload', 400);

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertStringContainsString('Silo:', $result);
        // findUserByEmail is called exactly once — by strict resolve.
        // Recovery short-circuits on a 4xx without re-querying.
        self::assertSame(1, $client->countCalls('findUserByEmail'));
    }

    public function testStrictResolveOutageRefusesToCreateDuplicate(): void
    {
        // The whole point of strict-resolve in the create path: if we
        // can't trust the negative ("no existing user"), we must NOT
        // create one.
        $client = new FakeClient();
        $client->findUserByEmailError = new SiloApiException('5xx', 503);

        $result = (new CreateAccount(Context::make($client)))->handle(Context::params());

        self::assertStringContainsString('refusing to create a duplicate', $result);
        self::assertFalse($client->called('createUser'));
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
