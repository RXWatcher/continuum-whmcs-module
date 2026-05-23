<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Handler\ChangePackage;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

/**
 * ChangePackage also backs the "Reconcile from WHMCS" button. These lock
 * the status-aware enabled-state fix: WHMCS passes no status in $params,
 * so it is read from tblhosting.domainstatus with a fail-safe default.
 */
final class ChangePackageTest extends TestCase
{
    private function resolvedClient(int $userId = 50): FakeClient
    {
        $client = new FakeClient();
        $client->usersById[$userId] = ['id' => $userId];
        return $client;
    }

    /** @return array<string, mixed> */
    private function params(int $userId = 50): array
    {
        return Context::params(['customfields' => ['silo_user_id' => (string)$userId]]);
    }

    public function testActiveServiceEnablesUser(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => 'Active']]);
        $client = $this->resolvedClient();

        $result = (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($client->lastUpdateUserPayload()['enabled']);
    }

    public function testSuspendedServiceDisablesUser(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => 'Suspended']]);
        $client = $this->resolvedClient();

        (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertFalse($client->lastUpdateUserPayload()['enabled']);
    }

    public function testMissingHostingRowFailsSafeToEnabled(): void
    {
        // No tblhosting seeded → domainstatus lookup returns null.
        $client = $this->resolvedClient();

        (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertTrue(
            $client->lastUpdateUserPayload()['enabled'],
            'a DB miss must never silently lock out a working customer'
        );
    }

    public function testEmptyStatusFailsSafeToEnabled(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => '']]);
        $client = $this->resolvedClient();

        (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertTrue($client->lastUpdateUserPayload()['enabled']);
    }

    public function testUnresolvedUserReturnsErrorAndDoesNotUpdate(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => 'Active']]);
        $client = new FakeClient(); // nothing resolvable

        $result = (new ChangePackage(Context::make($client)))->handle(Context::params());

        self::assertSame('No Silo user is linked to this service.', $result);
        self::assertFalse($client->called('updateUser'));
    }

    public function testApiErrorIsReportedAsHumanError(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => 'Active']]);
        $client = $this->resolvedClient();
        $client->updateUserError = new SiloApiException('boom', 500);

        $result = (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertSame('Silo returned a server error. Check Module Log for details.', $result);
    }
}
