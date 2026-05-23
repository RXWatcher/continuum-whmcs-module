<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Handler\AdminResetPassword;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class AdminResetPasswordTest extends TestCase
{
    private function resolved(): FakeClient
    {
        $client = new FakeClient();
        $client->usersById[81] = ['id' => 81];
        return $client;
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        return Context::params(['customfields' => ['silo_user_id' => '81']]);
    }

    public function testUnresolvedUserReturnsError(): void
    {
        $result = (new AdminResetPassword(Context::make(new FakeClient())))
            ->handle(Context::params(['username' => '', 'clientsdetails' => ['email' => '']]));

        self::assertSame('No Silo user is linked to this service.', $result);
    }

    public function testGeneratesPasswordPushesItAndWritesBackToWhmcs(): void
    {
        $client = $this->resolved();

        $result = (new AdminResetPassword(Context::make($client)))->handle($this->params());

        self::assertSame('success', $result);

        $sent = $client->lastUpdateUserPayload();
        self::assertIsString($sent['password']);
        self::assertGreaterThanOrEqual(32, strlen($sent['password']), 'should be a strong random secret');

        $writeBacks = array_filter(
            FakeWhmcs::$updateClientProduct,
            static fn($p) => isset($p['servicepassword'])
        );
        self::assertCount(1, $writeBacks);
        self::assertSame(
            $sent['password'],
            array_values($writeBacks)[0]['servicepassword'],
            'WHMCS service password must match what was pushed to Silo'
        );
    }

    public function testApiErrorIsHumanised(): void
    {
        $client = $this->resolved();
        $client->updateUserError = new SiloApiException('boom', 502);

        $result = (new AdminResetPassword(Context::make($client)))->handle($this->params());

        self::assertSame('Silo returned a server error. Check Module Log for details.', $result);
    }

    public function testWriteBackFailureDegradesToSuccessWithWarning(): void
    {
        $client = $this->resolved();
        FakeWhmcs::$localApiHandler = static function (string $action) {
            if ($action === 'UpdateClientProduct') {
                throw new \RuntimeException('whmcs db down');
            }
            return null;
        };

        $result = (new AdminResetPassword(Context::make($client)))->handle($this->params());

        self::assertStringStartsWith('success (warning:', $result);
        self::assertStringContainsString('whmcs db down', $result);
    }
}
