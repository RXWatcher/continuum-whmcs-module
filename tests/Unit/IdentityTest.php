<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Identity;
use Silo\WhmcsModule\Identity\Params;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class IdentityTest extends TestCase
{
    public function testTier1CustomFieldIdResolvesViaGetUser(): void
    {
        $client = new FakeClient();
        $client->usersById[5] = ['id' => 5];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['silo_user_id' => '5']])
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
            Context::params(['customfields' => ['silo_user_id' => '5']])
        );

        self::assertSame(9, $id);
    }

    public function testTier1ApiErrorFallsThroughInsteadOfThrowing(): void
    {
        $client = new FakeClient();
        $client->getUserError = new SiloApiException('stale', 404);
        $client->usersByEmail['jane@example.com'] = ['id' => 12];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['silo_user_id' => '5']])
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
        $client->findUserByEmailError = new SiloApiException('5xx', 503);

        $id = (new Identity($client))->resolve(Context::params());

        self::assertNull($id, 'an API outage must not let an exception escape the hook');
    }

    public function testTier1RejectsUserWhoseEmailDoesNotMatchWhmcsClient(): void
    {
        // Stale / hand-edited silo_user_id points at a DIFFERENT
        // customer. Without verification, every subsequent write (email
        // rename, password reset, …) would hit the wrong account. The
        // resolve must fall through to tier 2 (email lookup) instead.
        $client = new FakeClient();
        $client->usersById[42] = ['id' => 42, 'email' => 'someone-else@example.com'];
        $client->usersByEmail['jane@example.com'] = ['id' => 9, 'email' => 'jane@example.com'];

        $id = (new Identity($client))->resolve(Context::params([
            'customfields' => ['silo_user_id' => '42'],
        ]));

        self::assertSame(9, $id, 'must heal via tier-2 email lookup');
    }

    public function testTier1AcceptsUserWhenEmailMatchesCaseInsensitively(): void
    {
        $client = new FakeClient();
        $client->usersById[42] = ['id' => 42, 'email' => 'JANE@example.com'];

        $id = (new Identity($client))->resolve(Context::params([
            'customfields' => ['silo_user_id' => '42'],
            'clientsdetails' => ['email' => 'jane@example.com'],
        ]));

        self::assertSame(42, $id);
        self::assertFalse($client->called('findUserByEmail'), 'tier 1 verified; no fallback needed');
    }

    public function testTier1AcceptsUserWhoseRecordHasNoEmailField(): void
    {
        // Legacy / partial Silo responses may omit the email key.
        // Treat as unverifiable rather than wrong, so the module keeps
        // working against older Silo builds.
        $client = new FakeClient();
        $client->usersById[42] = ['id' => 42];

        $id = (new Identity($client))->resolve(Context::params([
            'customfields' => ['silo_user_id' => '42'],
        ]));

        self::assertSame(42, $id);
    }

    public function testStrictResolveReThrowsOnTier2ApiOutage(): void
    {
        $client = new FakeClient();
        $client->findUserByEmailError = new SiloApiException('5xx', 503);

        $this->expectException(SiloApiException::class);
        (new Identity($client))->resolve(Context::params(), strict: true);
    }

    public function testStrictResolveStillFallsThroughOnTier1404(): void
    {
        // 404 on getUser is the expected "stale ID" path — strict mode
        // must still let tier 2/3 heal a stale custom field.
        $client = new FakeClient();
        $client->getUserError = new SiloApiException('not found', 404);
        $client->usersByEmail['jane@example.com'] = ['id' => 12, 'email' => 'jane@example.com'];

        $id = (new Identity($client))->resolve(
            Context::params(['customfields' => ['silo_user_id' => '5']]),
            strict: true
        );

        self::assertSame(12, $id);
    }

    public function testStrictResolveReThrowsOnTier1ServerError(): void
    {
        $client = new FakeClient();
        $client->getUserError = new SiloApiException('server error', 502);

        $this->expectException(SiloApiException::class);
        (new Identity($client))->resolve(
            Context::params(['customfields' => ['silo_user_id' => '5']]),
            strict: true
        );
    }

    public function testStaticExtractors(): void
    {
        $params = Context::params([
            'customfields' => ['silo_user_id' => ' 42 '],
            'clientsdetails' => ['email' => '  Jane@Ex.com '],
            'username' => '  svc  ',
        ]);

        self::assertSame(42, Params::siloUserId($params));
        self::assertSame('jane@ex.com', Params::email($params));
        self::assertSame('svc', Params::username($params));
        self::assertNull(Params::siloUserId(
            Context::params(['customfields' => ['silo_user_id' => 'NaN']])
        ));
    }
}
