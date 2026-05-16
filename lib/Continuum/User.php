<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Continuum;

/**
 * Typed Continuum user. Email is lowercased on construction (Continuum
 * stores emails case-sensitively, but every WHMCS-side comparison should
 * happen lowercased). `raw` retains the full decoded API body so callers
 * can read fields the value object doesn't model yet.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $username,
        public readonly bool $enabled,
        public readonly string $role,
        /** @var int[] */
        public readonly array $libraryIds,
        public readonly int $maxStreams,
        public readonly ?string $lastActiveAt,
        /** @var array<string, mixed> */
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromApi(array $body): self
    {
        return new self(
            id: (int)($body['id'] ?? 0),
            email: strtolower(trim((string)($body['email'] ?? ''))),
            username: (string)($body['username'] ?? ''),
            enabled: (bool)($body['enabled'] ?? false),
            role: (string)($body['role'] ?? 'user'),
            libraryIds: array_map('intval', $body['library_ids'] ?? []),
            maxStreams: (int)($body['max_streams'] ?? 0),
            lastActiveAt: isset($body['last_active_at']) ? (string)$body['last_active_at'] : null,
            raw: $body,
        );
    }
}
