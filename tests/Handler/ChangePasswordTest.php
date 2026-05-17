<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\ChangePassword;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class ChangePasswordTest extends TestCase
{
    private function resolved(): FakeClient
    {
        $client = new FakeClient();
        $client->usersById[80] = ['id' => 80];
        return $client;
    }

    /** @return array<string, mixed> */
    private function params(array $overrides = []): array
    {
        return Context::params(array_replace_recursive(
            ['customfields' => ['continuum_user_id' => '80']],
            $overrides
        ));
    }

    public function testUnresolvedUserReturnsError(): void
    {
        $result = (new ChangePassword(Context::make(new FakeClient())))
            ->handle(Context::params(['username' => '', 'clientsdetails' => ['email' => '']]));

        self::assertSame('No Continuum user is linked to this service.', $result);
    }

    public function testEmptyPasswordIsRejected(): void
    {
        $result = (new ChangePassword(Context::make($this->resolved())))
            ->handle($this->params(['password' => '']));

        self::assertSame('WHMCS did not provide a password to change.', $result);
    }

    public function testPasswordAndSyncFieldsAreSent(): void
    {
        $client = $this->resolved();

        $result = (new ChangePassword(Context::make($client)))
            ->handle($this->params(['password' => 'n3wpass']));

        self::assertSame('success', $result);
        $payload = $client->lastUpdateUserPayload();
        self::assertSame('n3wpass', $payload['password']);
        self::assertSame('jane@example.com', $payload['email']);   // syncFields
        self::assertSame('svc_user', $payload['username']);
    }

    public function testApiErrorIsHumanised(): void
    {
        $client = $this->resolved();
        $client->updateUserError = new ContinuumApiException('nope', 500);

        $result = (new ChangePassword(Context::make($client)))->handle($this->params());

        self::assertSame('Continuum returned a server error. Check Module Log for details.', $result);
    }
}
