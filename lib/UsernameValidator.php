<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

final class UsernameValidator
{
    private const FORMAT = '/^[a-z0-9_-]{3,32}$/';

    /** Built-in reserved names. Operators can extend via the constructor. */
    private const BUILTIN_RESERVED = [
        'admin', 'root', 'system', 'support', 'null', 'undefined',
        'me', 'you', 'staff', 'help', 'about', 'login', 'logout',
        'continuum',
    ];

    /** @var array<string, bool> case-folded */
    private array $reserved;

    /** @param string[] $extraReserved operator-supplied additions */
    public function __construct(private BadWordList $badWords, array $extraReserved = [])
    {
        $this->reserved = [];
        foreach (array_merge(self::BUILTIN_RESERVED, $extraReserved) as $r) {
            $this->reserved[strtolower(trim($r))] = true;
        }
    }

    /**
     * Validate a candidate username. Returns null on pass, an error
     * message string on fail. Format → Reserved → Profanity, in order;
     * uniqueness is the caller's responsibility (needs the live Client).
     */
    public function validate(string $candidate): ?string
    {
        if (preg_match(self::FORMAT, $candidate) !== 1) {
            return 'Username must be 3-32 lowercase letters, digits, underscores, or hyphens.';
        }
        if (isset($this->reserved[strtolower($candidate)])) {
            return 'That username is reserved.';
        }
        if ($this->badWords->contains($candidate)) {
            return "That username isn't allowed.";
        }
        return null;
    }
}
