<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Continuum;

/**
 * Boundary between this module and Continuum's admin HTTP API.
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

    public function baseUrlForDeepLink(): string;
}
