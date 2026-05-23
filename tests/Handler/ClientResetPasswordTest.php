<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Handler\ClientResetPassword;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class ClientResetPasswordTest extends TestCase
{
    private function resolved(): FakeClient
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5];
        return $client;
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        return Context::params(['customfields' => ['silo_user_id' => '5']]);
    }

    public function testUnlinkedAccountReturnsFriendlyMessage(): void
    {
        $result = (new ClientResetPassword(Context::make(new FakeClient())))
            ->handle(Context::params(['username' => '', 'clientsdetails' => ['email' => '']]));

        self::assertStringContainsString('not linked yet', $result);
    }

    public function testResetsPushesPasswordAndReportsSignOut(): void
    {
        $client = $this->resolved();

        $result = (new ClientResetPassword(Context::make($client)))->handle($this->params());

        $sent = $client->lastUpdateUserPayload()['password'];
        self::assertIsString($sent);
        self::assertGreaterThanOrEqual(32, strlen($sent));
        self::assertStringContainsString($sent, $result, 'new password shown to the customer once');
        self::assertStringContainsString('signed out on all devices', $result);

        $writeBacks = array_filter(
            FakeWhmcs::$updateClientProduct,
            static fn($p) => isset($p['servicepassword'])
        );
        self::assertSame($sent, array_values($writeBacks)[0]['servicepassword']);
    }

    public function testApiFailureIsReportedWithoutLeakingInternals(): void
    {
        $client = $this->resolved();
        $client->updateUserError = new SiloApiException('boom', 500);

        $result = (new ClientResetPassword(Context::make($client)))->handle($this->params());

        self::assertStringContainsString('Could not reset your password', $result);
        self::assertStringContainsString('server error', $result);
    }

    public function testWriteBackFailureStillTellsCustomerThePassword(): void
    {
        $client = $this->resolved();
        FakeWhmcs::$localApiHandler = static function (string $action) {
            if ($action === 'UpdateClientProduct') {
                throw new \RuntimeException('whmcs db down');
            }
            return null;
        };

        $result = (new ClientResetPassword(Context::make($client)))->handle($this->params());

        $sent = $client->lastUpdateUserPayload()['password'];
        self::assertStringContainsString($sent, $result);
        self::assertStringContainsString('could not be stored on this service', $result);
        self::assertStringContainsString('signed out on all devices', $result);
    }
}
