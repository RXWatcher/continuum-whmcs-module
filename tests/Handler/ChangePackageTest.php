<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\ChangePackage;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;

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
        return Context::params(['customfields' => ['continuum_user_id' => (string)$userId]]);
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

        self::assertSame('No Continuum user is linked to this service.', $result);
        self::assertFalse($client->called('updateUser'));
    }

    public function testApiErrorIsReportedAsHumanError(): void
    {
        FakeWhmcs::seedTable('tblhosting', [['id' => 7, 'domainstatus' => 'Active']]);
        $client = $this->resolvedClient();
        $client->updateUserError = new ContinuumApiException('boom', 500);

        $result = (new ChangePackage(Context::make($client)))->handle($this->params());

        self::assertSame('Continuum returned a server error. Check Module Log for details.', $result);
    }
}
