<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Tests\Support\Context;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class ServerRegistryTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $extra */
    private function seedServers(array $rows): void
    {
        FakeWhmcs::seedTable('tblservers', $rows);
    }

    private function row(int $id, string $key, int $disabled = 0): array
    {
        return [
            'id' => $id, 'type' => 'silo', 'hostname' => "srv{$id}",
            'port' => '', 'secure' => 1, 'password' => $key, 'disabled' => $disabled,
        ];
    }

    public function testFindsUserByEmailOnTheHostingServer(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2')]);
        $other = new FakeClient();
        $other->usersByEmail['jane@example.com'] = ['id' => 77, 'username' => 'jhandle'];

        $home = Context::serverRegistry(['key1' => new FakeClient(), 'key2' => $other])
            ->findHome('jane@example.com');

        self::assertSame(2, $home['serverId']);
        self::assertSame(77, $home['userId']);
        self::assertSame('jhandle', $home['username']);
        self::assertSame($other, $home['client']);
    }

    public function testPreferredServerIsProbedFirst(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2')]);
        $s1 = new FakeClient();
        $s1->usersByEmail['jane@example.com'] = ['id' => 11];
        $s2 = new FakeClient();
        $s2->usersByEmail['jane@example.com'] = ['id' => 22];

        $home = Context::serverRegistry(['key1' => $s1, 'key2' => $s2])
            ->findHome('jane@example.com', '', preferServerId: 2);

        self::assertSame(2, $home['serverId'], 'pointer/preferred server wins the tie');
        self::assertSame(22, $home['userId']);
    }

    public function testDisabledServersAreSkipped(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2', disabled: 1)]);
        $s2 = new FakeClient();
        $s2->usersByEmail['jane@example.com'] = ['id' => 99];

        $home = Context::serverRegistry(['key1' => new FakeClient(), 'key2' => $s2])
            ->findHome('jane@example.com');

        self::assertNull($home, 'a user only on a disabled server is unreachable');
    }

    public function testUnreachableServerDoesNotAbortTheScan(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2')]);
        $broken = new FakeClient();
        $broken->findUserByEmailError = new SiloApiException('5xx', 503);
        $good = new FakeClient();
        $good->usersByEmail['jane@example.com'] = ['id' => 5];

        $home = Context::serverRegistry(['key1' => $broken, 'key2' => $good])
            ->findHome('jane@example.com');

        self::assertSame(2, $home['serverId']);
    }

    public function testUsernameFallbackWhenEmailMisses(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2')]);
        $s2 = new FakeClient();
        $s2->usersByUsername['svc_user'] = ['id' => 8, 'username' => 'svc_user'];

        $home = Context::serverRegistry(['key1' => new FakeClient(), 'key2' => $s2])
            ->findHome('nobody@example.com', 'svc_user');

        self::assertSame(2, $home['serverId']);
        self::assertSame(8, $home['userId']);
    }

    public function testReturnsNullWhenNowhere(): void
    {
        $this->seedServers([$this->row(1, 'key1'), $this->row(2, 'key2')]);

        $home = Context::serverRegistry(['key1' => new FakeClient(), 'key2' => new FakeClient()])
            ->findHome('ghost@example.com', 'ghost');

        self::assertNull($home);
    }
}
