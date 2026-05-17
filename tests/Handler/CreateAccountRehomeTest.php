<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\Handler\CreateAccount;
use Continuum\WhmcsModule\HomeStore;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;

/**
 * Multi-server auto-re-home (config option auto_rehome_on_reorder /
 * configoption12). The assigned server is id 1; the customer's existing
 * Continuum user lives on server 2.
 */
final class CreateAccountRehomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeWhmcs::seedTable('tblservers', [
            ['id' => 1, 'type' => 'continuum', 'hostname' => 'srv1', 'port' => '',
             'secure' => 0, 'password' => 'key1', 'disabled' => 0],
            ['id' => 2, 'type' => 'continuum', 'hostname' => 'srv2', 'port' => '',
             'secure' => 0, 'password' => 'key2', 'disabled' => 0],
        ]);
        // The service row the move re-points (serviceid 7, on server 1).
        FakeWhmcs::seedTable('tblhosting', [
            ['id' => 7, 'server' => 1, 'domainstatus' => 'Active', 'packageid' => 3],
        ]);
    }

    private function serverOfService7(): int
    {
        return (int)(FakeWhmcs::rows('tblhosting')[0]['server'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function params(array $overrides = []): array
    {
        return Context::params(array_replace_recursive(
            ['serverid' => 1, 'configoption12' => 'on'],
            $overrides
        ));
    }

    public function testReHomesServiceToTheServerHostingTheUser(): void
    {
        $assigned = new FakeClient(); // server 1 — nothing here
        $other = new FakeClient();    // server 2 — the existing user
        $other->usersByEmail['jane@example.com'] = ['id' => 77, 'username' => 'oldhandle'];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params());

        self::assertSame('success', $result);
        self::assertFalse($assigned->called('createUser'), 'must not create a fresh account');

        // WHMCS API attempted with serverid…
        $moves = array_filter(
            FakeWhmcs::apiCalls('UpdateClientProduct'),
            static fn($p) => isset($p['serverid'])
        );
        self::assertSame(2, array_values($moves)[0]['serverid']);
        // …and the service row actually ends up on server 2 (the verified
        // direct-DB fallback guarantees it even if the API ignores serverid).
        self::assertSame(2, $this->serverOfService7());

        // Existing user re-enabled on its home server.
        self::assertSame([77, true], [
            $other->calls[array_key_last($other->calls)]['args'][0] ?? null,
            $other->lastUpdateUserPayload()['enabled'],
        ]);

        // Home pointer persisted for next time.
        self::assertSame(
            ['serverid' => 2, 'userid' => 77],
            (new HomeStore())->get('jane@example.com')
        );
        self::assertNotEmpty(array_filter(
            FakeWhmcs::$activityLog,
            static fn($m) => str_contains($m, 're-homed')
        ));
    }

    public function testPointerSteersToTheCorrectServerOnAmbiguousEmail(): void
    {
        // Same email exists on BOTH servers; the cached pointer must win.
        $assigned = new FakeClient();
        $assigned->usersByEmail['jane@example.com'] = ['id' => 11, 'username' => 'wrong'];
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77, 'username' => 'right'];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        // Assigned server's own client (used by Identity::resolve) is empty,
        // so resolution falls through to the re-home path.
        $home = new HomeStore();
        $home->put('jane@example.com', 2, 77);

        $result = (new CreateAccount(Context::make(new FakeClient(), $registry, $home)))
            ->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($other->called('updateUser'));
        self::assertFalse($assigned->called('updateUser'));
    }

    public function testToggleOffCreatesFreshAccountInsteadOfReHoming(): void
    {
        $assigned = new FakeClient();
        $assigned->createUserQueue[] = ['id' => 900];
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params(['configoption12' => '']));

        self::assertSame('success', $result);
        self::assertTrue($assigned->called('createUser'));
        self::assertFalse($other->called('updateUser'), 're-home disabled');
    }

    public function testNoHomeAnywhereCreatesFreshAndRecordsPointer(): void
    {
        $assigned = new FakeClient();
        $assigned->createUserQueue[] = ['id' => 901];
        $registry = Context::serverRegistry([
            'key1' => $assigned,
            'key2' => new FakeClient(),
        ]);

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($assigned->called('createUser'));
        self::assertSame(
            ['serverid' => 1, 'userid' => 901],
            (new HomeStore())->get('jane@example.com'),
            'fresh account still records its home for future re-orders'
        );
    }

    public function testDbFallbackMovesServiceWhenWhmcsApiIgnoresServerid(): void
    {
        // The realistic worry: UpdateClientProduct "succeeds" but does not
        // re-point the service. The verified direct-DB fallback must still
        // complete the move (no error, history preserved).
        $assigned = new FakeClient();
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77, 'username' => 'h'];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        FakeWhmcs::$localApiHandler = static fn(string $a) => null; // API no-ops

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params());

        self::assertSame('success', $result);
        self::assertSame(2, $this->serverOfService7(), 'direct-DB fallback completed the move');
        self::assertTrue($other->called('updateUser'));
        self::assertFalse($assigned->called('createUser'));
    }

    public function testMoveFailsLoudlyWhenBothApiAndDbFail(): void
    {
        $assigned = new FakeClient();
        $assigned->createUserQueue[] = ['id' => 999];
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        // API throws AND the direct tblhosting write throws.
        FakeWhmcs::$localApiHandler = static function (string $action) {
            if ($action === 'UpdateClientProduct') {
                throw new \RuntimeException('whmcs move failed');
            }
            return null;
        };
        FakeWhmcs::$throwForTable = 'tblhosting';

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params());

        self::assertStringStartsWith('Re-home failed:', $result);
        self::assertFalse($assigned->called('createUser'), 'must NOT silently orphan history');
        self::assertFalse($other->called('updateUser'));
    }

    public function testUserOnlyOnDisabledServerFallsBackToFreshAccount(): void
    {
        FakeWhmcs::seedTable('tblservers', [
            ['id' => 1, 'type' => 'continuum', 'hostname' => 'srv1', 'port' => '',
             'secure' => 0, 'password' => 'key1', 'disabled' => 0],
            ['id' => 2, 'type' => 'continuum', 'hostname' => 'srv2', 'port' => '',
             'secure' => 0, 'password' => 'key2', 'disabled' => 1],
        ]);
        $assigned = new FakeClient();
        $assigned->createUserQueue[] = ['id' => 902];
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77];
        $registry = Context::serverRegistry(['key1' => $assigned, 'key2' => $other]);

        $result = (new CreateAccount(Context::make($assigned, $registry)))
            ->handle($this->params());

        self::assertSame('success', $result);
        self::assertTrue($assigned->called('createUser'));
        self::assertFalse($other->called('updateUser'));
    }
}
