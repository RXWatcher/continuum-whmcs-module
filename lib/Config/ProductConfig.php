<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Config;

final class ProductConfig
{
    private string $role;
    /** @var int[] */
    private array $libraryIds;
    private int $maxStreams;
    private int $maxTranscodes;
    private int $maxProfiles;
    private bool $downloadAllowed;
    private bool $downloadTranscodeAllowed;
    private string $maxPlaybackQuality;
    private bool $createDefaultProfile;
    private bool $allowUserChosenUsername;
    private string $configurableOptionsMapJson;

    private function __construct(array $a)
    {
        $this->role = $a['role'];
        $this->libraryIds = $a['libraryIds'];
        $this->maxStreams = $a['maxStreams'];
        $this->maxTranscodes = $a['maxTranscodes'];
        $this->maxProfiles = $a['maxProfiles'];
        $this->downloadAllowed = $a['downloadAllowed'];
        $this->downloadTranscodeAllowed = $a['downloadTranscodeAllowed'];
        $this->maxPlaybackQuality = $a['maxPlaybackQuality'];
        $this->createDefaultProfile = $a['createDefaultProfile'];
        $this->allowUserChosenUsername = $a['allowUserChosenUsername'];
        $this->configurableOptionsMapJson = $a['configurableOptionsMapJson'];
    }

    /** @param array<string, mixed> $params */
    public static function fromParams(array $params): self
    {
        $role = (string)($params['configoption1'] ?? 'user');
        if ($role === '') {
            $role = 'user';
        }
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new \InvalidArgumentException("Invalid role '{$role}', must be 'user' or 'admin'");
        }

        $libRaw = (string)($params['configoption2'] ?? '');
        $libraryIds = [];
        foreach (explode(',', $libRaw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (!ctype_digit($tok)) {
                throw new \InvalidArgumentException("Library IDs must be comma-separated integers; got '{$tok}'");
            }
            $libraryIds[] = (int)$tok;
        }

        return new self([
            'role' => $role,
            'libraryIds' => $libraryIds,
            'maxStreams' => self::readInt($params, 'configoption3', 6),
            'maxTranscodes' => self::readInt($params, 'configoption4', 2),
            'maxProfiles' => self::readInt($params, 'configoption5', 5),
            'downloadAllowed' => self::readYesNo($params, 'configoption6', true),
            'downloadTranscodeAllowed' => self::readYesNo($params, 'configoption7', false),
            'maxPlaybackQuality' => self::readQuality($params),
            'createDefaultProfile' => self::readYesNo($params, 'configoption9', true),
            'allowUserChosenUsername' => self::readYesNo($params, 'configoption10', false),
            'configurableOptionsMapJson' => (string)($params['configoption11'] ?? ''),
        ]);
    }

    private static function readInt(array $params, string $key, int $default): int
    {
        $raw = trim((string)($params[$key] ?? ''));
        if ($raw === '') {
            return $default;
        }
        if (!ctype_digit($raw)) {
            throw new \InvalidArgumentException("Field {$key} must be a non-negative integer; got '{$raw}'");
        }
        return (int)$raw;
    }

    private static function readYesNo(array $params, string $key, bool $default): bool
    {
        $raw = trim((string)($params[$key] ?? ''));
        if ($raw === '') {
            return $default;
        }
        return $raw === 'yes' || $raw === 'on' || $raw === '1';
    }

    private static function readQuality(array $params): string
    {
        $raw = trim((string)($params['configoption8'] ?? ''));
        if ($raw === '') {
            return '';
        }
        $allowed = ['4k', '1080p', '720p', '480p'];
        if (!in_array($raw, $allowed, true)) {
            throw new \InvalidArgumentException(
                "max_playback_quality must be one of: '' (unrestricted), " . implode(', ', $allowed)
            );
        }
        return $raw;
    }

    public function role(): string
    {
        return $this->role;
    }

    /** @return int[] */
    public function libraryIds(): array
    {
        return $this->libraryIds;
    }

    public function maxStreams(): int
    {
        return $this->maxStreams;
    }

    public function maxTranscodes(): int
    {
        return $this->maxTranscodes;
    }

    public function maxProfiles(): int
    {
        return $this->maxProfiles;
    }

    public function downloadAllowed(): bool
    {
        return $this->downloadAllowed;
    }

    public function downloadTranscodeAllowed(): bool
    {
        return $this->downloadTranscodeAllowed;
    }

    public function maxPlaybackQuality(): string
    {
        return $this->maxPlaybackQuality;
    }

    public function createDefaultProfile(): bool
    {
        return $this->createDefaultProfile;
    }

    public function allowUserChosenUsername(): bool
    {
        return $this->allowUserChosenUsername;
    }

    public function configurableOptionsMapJson(): string
    {
        return $this->configurableOptionsMapJson;
    }
}
