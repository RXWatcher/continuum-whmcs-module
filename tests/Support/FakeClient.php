<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Support;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\ContinuumApiException;

/**
 * Scriptable in-memory Continuum API client. Records every call so tests
 * can assert on payloads (notably the `enabled` flag).
 */
final class FakeClient implements ClientInterface
{
    /** @var array<int, array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>>  userId => user record for getUser() */
    public array $usersById = [];

    /** @var array<string, array<string, mixed>>  email => user record */
    public array $usersByEmail = [];

    /** @var array<string, array<string, mixed>>  username => user record */
    public array $usersByUsername = [];

    /** @var array<int, array<string, mixed>|ContinuumApiException>  consumed FIFO by createUser() */
    public array $createUserQueue = [];

    /** @var array<string, mixed> */
    public array $updateUserResult = ['id' => 0];

    public ?ContinuumApiException $updateUserError = null;
    public ?ContinuumApiException $deleteUserError = null;
    public ?ContinuumApiException $getUserError = null;
    public ?ContinuumApiException $findUserByEmailError = null;
    public ?ContinuumApiException $findUserByUsernameError = null;

    /** @var array<int, array<string, mixed>> */
    public array $libraries = [];
    public ?\Throwable $listLibrariesError = null;

    public function createUser(array $payload): array
    {
        $this->calls[] = ['method' => 'createUser', 'args' => [$payload]];
        $next = array_shift($this->createUserQueue);
        if ($next instanceof ContinuumApiException) {
            throw $next;
        }
        return $next ?? ['id' => 100];
    }

    public function updateUser(int $userId, array $payload): array
    {
        $this->calls[] = ['method' => 'updateUser', 'args' => [$userId, $payload]];
        if ($this->updateUserError !== null) {
            throw $this->updateUserError;
        }
        return $this->updateUserResult['id'] === 0 ? ['id' => $userId] : $this->updateUserResult;
    }

    public function deleteUser(int $userId): void
    {
        $this->calls[] = ['method' => 'deleteUser', 'args' => [$userId]];
        if ($this->deleteUserError !== null) {
            throw $this->deleteUserError;
        }
    }

    public function getUser(int $userId): array
    {
        $this->calls[] = ['method' => 'getUser', 'args' => [$userId]];
        if ($this->getUserError !== null) {
            throw $this->getUserError;
        }
        return $this->usersById[$userId] ?? [];
    }

    public function findUserByEmail(string $email): ?array
    {
        $this->calls[] = ['method' => 'findUserByEmail', 'args' => [$email]];
        if ($this->findUserByEmailError !== null) {
            throw $this->findUserByEmailError;
        }
        return $this->usersByEmail[strtolower($email)] ?? null;
    }

    public function findUserByUsername(string $username): ?array
    {
        $this->calls[] = ['method' => 'findUserByUsername', 'args' => [$username]];
        if ($this->findUserByUsernameError !== null) {
            throw $this->findUserByUsernameError;
        }
        return $this->usersByUsername[$username] ?? null;
    }

    public function listLibraries(): array
    {
        $this->calls[] = ['method' => 'listLibraries', 'args' => []];
        if ($this->listLibrariesError !== null) {
            throw $this->listLibrariesError;
        }
        return $this->libraries;
    }

    public function baseUrlForDeepLink(): string
    {
        return 'https://continuum.test';
    }

    // --- assertion helpers -------------------------------------------------

    /** @return array<string, mixed>|null payload of the most recent updateUser() */
    public function lastUpdateUserPayload(): ?array
    {
        for ($i = count($this->calls) - 1; $i >= 0; $i--) {
            if ($this->calls[$i]['method'] === 'updateUser') {
                return $this->calls[$i]['args'][1];
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null payload of the most recent createUser() */
    public function lastCreateUserPayload(): ?array
    {
        for ($i = count($this->calls) - 1; $i >= 0; $i--) {
            if ($this->calls[$i]['method'] === 'createUser') {
                return $this->calls[$i]['args'][0];
            }
        }
        return null;
    }

    public function called(string $method): bool
    {
        return $this->countCalls($method) > 0;
    }

    public function countCalls(string $method): int
    {
        return count(array_filter($this->calls, static fn($c) => $c['method'] === $method));
    }
}
