<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Silo;

/**
 * Boundary between this module and Silo's admin HTTP API.
 */
interface ClientInterface
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createUser(array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateUser(int $userId, array $payload): array;

    public function deleteUser(int $userId): void;

    /** @return array<string, mixed> */
    public function getUser(int $userId): array;

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array;

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $username): ?array;

    /** @return array<int, array<string, mixed>> */
    public function listLibraries(): array;

    /** @return array<int, array{id: string, name: string}> profiles for one user */
    public function listUserProfiles(int $userId): array;

    /** @return array<int, array<string, mixed>> server-wide active playback sessions */
    public function listSessions(): array;

    public function baseUrlForDeepLink(): string;
}
