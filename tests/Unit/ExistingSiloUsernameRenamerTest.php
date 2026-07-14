<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Tests\Support\FakeClient;
use Silo\WhmcsModule\Tests\Support\TestCase;
use Silo\WhmcsModule\Whmcs\ExistingSiloUsernameRenamer;

final class ExistingSiloUsernameRenamerTest extends TestCase
{
    public function testRenamesBothSystemsWithUsernameOnlyPayload(): void
    {
        $client = $this->statefulClient(42, 'oldname');
        $whmcs = 'oldname';
        $renamer = new ExistingSiloUsernameRenamer(
            $client,
            static function (int $serviceId, string $username) use (&$whmcs): void {
                $whmcs = $username;
            },
            static function (int $serviceId) use (&$whmcs): string { return $whmcs; },
            static fn(string $first, string $last, int $attempt): ?string => 'jcole007',
        );

        $result = $renamer->rename(7, 42, 'oldname', 'Jim', 'Cole');

        self::assertTrue($result['success']);
        self::assertSame('jcole007', $result['new_username']);
        self::assertSame('jcole007', $whmcs);
        self::assertSame(['username' => 'jcole007'], $client->lastUpdateUserPayload());
    }

    public function testCollisionUsesNextCandidate(): void
    {
        $client = $this->statefulClient(42, 'oldname');
        $client->usersByUsername['jcole001'] = ['id' => 99, 'username' => 'jcole001'];
        $whmcs = 'oldname';
        $candidates = ['jcole001', 'jcole002'];
        $renamer = new ExistingSiloUsernameRenamer(
            $client,
            static function (int $id, string $username) use (&$whmcs): void { $whmcs = $username; },
            static function (int $id) use (&$whmcs): string { return $whmcs; },
            static fn(string $first, string $last, int $attempt): ?string => $candidates[$attempt - 1],
        );

        $result = $renamer->rename(7, 42, 'oldname', 'Jim', 'Cole');

        self::assertTrue($result['success']);
        self::assertSame('jcole002', $result['new_username']);
    }

    public function testTenCollisionsLeaveOldUsernameUnchanged(): void
    {
        $client = $this->statefulClient(42, 'oldname');
        for ($i = 1; $i <= 10; $i++) {
            $name = 'jcole' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $client->usersByUsername[$name] = ['id' => 100 + $i, 'username' => $name];
        }
        $renamer = new ExistingSiloUsernameRenamer(
            $client,
            static function (): void {},
            static fn(): string => 'oldname',
            static fn(string $first, string $last, int $attempt): ?string =>
                'jcole' . str_pad((string)$attempt, 3, '0', STR_PAD_LEFT),
        );

        $result = $renamer->rename(7, 42, 'oldname', 'Jim', 'Cole');

        self::assertFalse($result['success']);
        self::assertFalse($result['critical_mismatch']);
        self::assertSame(0, $client->countCalls('updateUser'));
    }

    public function testWhmcsFailureRestoresBothSystems(): void
    {
        $client = $this->statefulClient(42, 'oldname');
        $whmcs = 'oldname';
        $writes = 0;
        $renamer = new ExistingSiloUsernameRenamer(
            $client,
            static function (int $id, string $username) use (&$whmcs, &$writes): void {
                if (++$writes === 1) {
                    throw new \RuntimeException('WHMCS write failed');
                }
                $whmcs = $username;
            },
            static function (int $id) use (&$whmcs): string { return $whmcs; },
            static fn(): ?string => 'jcole007',
        );

        $result = $renamer->rename(7, 42, 'oldname', 'Jim', 'Cole');

        self::assertFalse($result['success']);
        self::assertFalse($result['critical_mismatch']);
        self::assertSame('oldname', $client->usersById[42]['username']);
        self::assertSame('oldname', $whmcs);
    }

    public function testFailedCompensationReportsCriticalMismatch(): void
    {
        $client = new class extends FakeClient {
            private int $updates = 0;
            public function updateUser(int $userId, array $payload): array
            {
                if (++$this->updates === 2) {
                    throw new SiloApiException('rollback failed', 500);
                }
                $this->usersById[$userId]['username'] = (string)$payload['username'];
                return parent::updateUser($userId, $payload);
            }
        };
        $client->usersById[42] = ['id' => 42, 'username' => 'oldname'];
        $whmcs = 'oldname';
        $renamer = new ExistingSiloUsernameRenamer(
            $client,
            static function (int $id, string $username): void { throw new \RuntimeException('fail'); },
            static fn(int $id): string => $whmcs,
            static fn(): ?string => 'jcole007',
        );

        $result = $renamer->rename(7, 42, 'oldname', 'Jim', 'Cole');

        self::assertFalse($result['success']);
        self::assertTrue($result['critical_mismatch']);
    }

    private function statefulClient(int $userId, string $username): FakeClient
    {
        $client = new class extends FakeClient {
            public function updateUser(int $userId, array $payload): array
            {
                $old = (string)($this->usersById[$userId]['username'] ?? '');
                unset($this->usersByUsername[$old]);
                $this->usersById[$userId]['username'] = (string)$payload['username'];
                $this->usersByUsername[(string)$payload['username']] = $this->usersById[$userId];
                parent::updateUser($userId, $payload);
                return $this->usersById[$userId];
            }
        };
        $client->usersById[$userId] = ['id' => $userId, 'username' => $username];
        $client->usersByUsername[$username] = $client->usersById[$userId];
        return $client;
    }
}
