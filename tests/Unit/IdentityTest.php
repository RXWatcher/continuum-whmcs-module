<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Identity;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class IdentityTest extends TestCase
{
    public function testTier1CustomFieldIdResolvesViaGetUser(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['continuum_user_id' => '5']])
        );

        self::assertSame(5, $id);
        self::assertTrue($client->called('getUser'));
        self::assertFalse($client->called('findUserByEmail'), 'tier 1 should short-circuit');
    }

    public function testStaleCustomFieldIdFallsThroughToEmail(): void
    {
        $client = new FakeClient(); // getUser(5) returns [] → no id
        $client->usersByEmail['jane@example.com'] = ['id' => 9];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['continuum_user_id' => '5']])
        );

        self::assertSame(9, $id);
    }

    public function testTier1ApiErrorFallsThroughInsteadOfThrowing(): void
    {
        $client = new FakeClient();
        $client->getUserError = new ContinuumApiException('stale', 404);
        $client->usersByEmail['jane@example.com'] = ['id' => 12];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['continuum_user_id' => '5']])
        );

        self::assertSame(12, $id);
    }

    public function testEmailLookupIsCaseInsensitive(): void
    {
        $client = new FakeClient();
        $client->usersByEmail['jane@example.com'] = ['id' => 9];

        $id = (new Identity($client))->resolve(
            Context::params(['clientsdetails' => ['email' => 'Jane@Example.COM']])
        );

        self::assertSame(9, $id);
    }

    public function testTier3UsernameResolvesWhenIdAndEmailMiss(): void
    {
        $client = new FakeClient();
        $client->usersByUsername['svc_user'] = ['id' => 11];

        $id = (new Identity($client))->resolve(Context::params());

        self::assertSame(11, $id);
    }

    public function testNoSignalResolvesToNull(): void
    {
        $id = (new Identity(new FakeClient()))->resolve(Context::params([
            'username' => '',
            'clientsdetails' => ['email' => ''],
        ]));

        self::assertNull($id);
    }

    public function testUnreachableApiIsTreatedAsUnresolvedNotFatal(): void
    {
        $client = new FakeClient();
        $client->findUserByEmailError = new ContinuumApiException('5xx', 503);

        $id = (new Identity($client))->resolve(Context::params());

        self::assertNull($id, 'an API outage must not let an exception escape the hook');
    }

    public function testStaticExtractors(): void
    {
        $params = Context::params([
            'customfields' => ['continuum_user_id' => ' 42 '],
            'clientsdetails' => ['email' => '  Jane@Ex.com '],
            'username' => '  svc  ',
        ]);

        self::assertSame(42, Identity::idFromParams($params));
        self::assertSame('jane@ex.com', Identity::emailFromParams($params));
        self::assertSame('svc', Identity::usernameFromParams($params));
        self::assertNull(Identity::idFromParams(
            Context::params(['customfields' => ['continuum_user_id' => 'NaN']])
        ));
    }
}
