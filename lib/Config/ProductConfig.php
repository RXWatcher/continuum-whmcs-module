<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Config;

use Silo\WhmcsModule\PlaybackQuality;

final class ProductConfig
{
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

    private function __construct(array $a)
    {
        $this->libraryIds = $a['libraryIds'];
        $this->maxStreams = $a['maxStreams'];
        $this->maxTranscodes = $a['maxTranscodes'];
        $this->maxProfiles = $a['maxProfiles'];
        $this->downloadAllowed = $a['downloadAllowed'];
        $this->downloadTranscodeAllowed = $a['downloadTranscodeAllowed'];
        $this->maxPlaybackQuality = $a['maxPlaybackQuality'];
        $this->createDefaultProfile = $a['createDefaultProfile'];
        $this->allowUserChosenUsername = $a['allowUserChosenUsername'];
    }

    /** @param array<string, mixed> $params */
    public static function fromParams(array $params): self
    {
        $libRaw = (string)($params['configoption1'] ?? '');
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
            'libraryIds' => $libraryIds,
            'maxStreams' => self::readInt($params, 'configoption2', 6),
            'maxTranscodes' => self::readInt($params, 'configoption3', 2),
            'maxProfiles' => self::readInt($params, 'configoption4', 5),
            'downloadAllowed' => self::readYesNo($params, 'configoption5', true),
            'downloadTranscodeAllowed' => self::readYesNo($params, 'configoption6', false),
            'maxPlaybackQuality' => self::readQuality($params),
            'createDefaultProfile' => self::readYesNo($params, 'configoption8', true),
            'allowUserChosenUsername' => self::readYesNo($params, 'configoption9', false),
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

    /**
     * Whether terminating the WHMCS service should DELETE the Silo
     * user (vs. only disabling it). configoption10, default ON. Read
     * standalone — no full ProductConfig validation — so an unrelated
     * config error can never block a termination.
     */
    public static function deleteOnTerminate(array $params): bool
    {
        return self::readYesNo($params, 'configoption10', true);
    }

    /**
     * Whether a new order should be re-homed to a server that already
     * hosts this customer's Silo user (multi-server). configoption11,
     * default OFF. Read standalone like deleteOnTerminate so it never
     * passes through validation that could block provisioning.
     */
    public static function autoRehome(array $params): bool
    {
        return self::readYesNo($params, 'configoption11', false);
    }

    /**
     * Whether customers can use the client-area "reset password & sign out"
     * action. configoption12, default ON for backwards compatibility.
     */
    public static function allowClientResetPassword(array $params): bool
    {
        return self::readYesNo($params, 'configoption12', true);
    }

    /**
     * Cooldown (seconds) between client-area password resets for one
     * service. configoption13, default 60. 0 (or blank treated as
     * default) — a value of 0 disables the throttle. Read standalone so
     * a config typo can never block a reset; an invalid value falls back
     * to the 60s default rather than throwing.
     */
    public static function clientResetCooldownSeconds(array $params): int
    {
        $raw = trim((string)($params['configoption13'] ?? ''));
        if ($raw === '') {
            return 60;
        }
        return ctype_digit($raw) ? (int)$raw : 60;
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
        // Canonicalise to what Silo enforces ('', 1080p, 4k).
        // Legacy 720p/480p product settings degrade to 1080p rather than
        // failing provisioning (that is Silo's real behaviour).
        return PlaybackQuality::canonical((string)($params['configoption7'] ?? ''));
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

}
