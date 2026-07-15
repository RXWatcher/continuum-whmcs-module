<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;
use Silo\WhmcsModule\PlaybackQuality;

final class ClientArea
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): array
    {
        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return ['templatefile' => 'clientarea', 'vars' => [
                'error' => 'Your Silo account is not yet linked. Contact support.',
            ]];
        }
        $this->ensureLinkage($this->ctx, $params, $userId);

        $vars = [
            'status' => 'active',
            'username' => '',
            'member_since' => '',
            'stream_limit' => null,
            'transcode_limit' => null,
            'profile_limit' => null,
            'downloads' => null,
            'transcoded_downloads' => null,
            'quality' => 'Unrestricted',
            'profiles_used' => null,
            'profile_names' => [],
            'active_streams' => null,
            'now_watching' => [],
            'library_names' => [],
            'last_seen_relative' => 'never',
            'login_url' => $this->ctx->client()->baseUrlForDeepLink() . '/',
        ];
        $canShowActiveStreams = false;

        try {
            $user = $this->ctx->client()->getUser($userId);
            $vars['username'] = (string)($user['username'] ?? '');
            $vars['stream_limit'] = (int)($user['max_streams'] ?? 0);
            $vars['transcode_limit'] = (int)($user['max_transcodes'] ?? 0);
            $vars['profile_limit'] = (int)($user['max_profiles'] ?? 0);
            $vars['quality'] = $this->humanQuality((string)($user['max_playback_quality'] ?? ''));
            $vars['downloads'] = ($user['download_allowed'] ?? false) ? 'Allowed' : 'Not allowed';
            $vars['transcoded_downloads'] = ($user['download_transcode_allowed'] ?? false)
                ? 'Allowed'
                : 'Not allowed';
            $vars['member_since'] = $this->humanDate((string)($user['created_at'] ?? ''));
            if (!($user['enabled'] ?? true)) {
                $vars['status'] = 'suspended';
            } else {
                $canShowActiveStreams = true;
            }
            if (!empty($user['last_active_at'])) {
                $vars['last_seen_relative'] = $this->humanRelativeTime((string)$user['last_active_at']);
            }
            // null library_ids means unrestricted access (all libraries),
            // distinct from an empty list. Show that explicitly rather
            // than a blank cell.
            $libIds = $user['library_ids'] ?? null;
            $vars['library_names'] = $libIds === null
                ? ['All libraries']
                : $this->resolveLibraryNames($params, $libIds);
        } catch (SiloApiException $e) {
            $vars['status'] = 'active (status unavailable)';
        }

        // Profiles and active streams are independent best-effort
        // enrichments: a failure of either must not blank the whole page.
        try {
            $profiles = $this->ctx->client()->listUserProfiles($userId);
            $vars['profiles_used'] = count($profiles);
            $vars['profile_names'] = array_values(array_filter(array_map(
                static fn($p) => (string)($p['name'] ?? ''),
                is_array($profiles) ? $profiles : []
            )));
        } catch (\Throwable $e) {
            // leave profiles_used null → template hides the row
        }

        if ($canShowActiveStreams) {
            try {
                $vars['now_watching'] = $this->activeStreamLabels($userId);
                $vars['active_streams'] = count($vars['now_watching']);
            } catch (\Throwable $e) {
                // leave active_streams null → template hides the row
            }
        }

        return ['templatefile' => 'clientarea', 'vars' => $vars];
    }

    /**
     * Human labels for this user's currently-playing sessions. Never
     * exposes client_ip / bitrates — customer-facing, PII-free.
     *
     * @return string[]
     */
    private function activeStreamLabels(int $userId): array
    {
        $out = [];
        foreach ($this->ctx->client()->listSessions() as $s) {
            if (!is_array($s) || (int)($s['user_id'] ?? -1) !== $userId) {
                continue;
            }
            $series = trim((string)($s['series_name'] ?? ''));
            if ($series !== '') {
                $label = $series;
                $se = sprintf(
                    'S%dE%d',
                    (int)($s['season_number'] ?? 0),
                    (int)($s['episode_number'] ?? 0)
                );
                $label .= ' — ' . $se;
                $ep = trim((string)($s['episode_name'] ?? ''));
                if ($ep !== '') {
                    $label .= ' · ' . $ep;
                }
            } else {
                $label = trim((string)($s['media_title'] ?? 'Untitled'));
            }
            if (!empty($s['is_paused'])) {
                $label .= ' (paused)';
            }
            $out[] = $label;
        }
        return $out;
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->format('M j, Y');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function humanQuality(string $q): string
    {
        // $q comes from Silo ('', '1080p', '2160p').
        return PlaybackQuality::human($q);
    }

    private function humanRelativeTime(string $iso): string
    {
        try {
            $then = new \DateTimeImmutable($iso);
        } catch (\Throwable $e) {
            return 'unknown';
        }
        $diff = (new \DateTimeImmutable())->getTimestamp() - $then->getTimestamp();
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' minutes ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' hours ago';
        }
        return floor($diff / 86400) . ' days ago';
    }

    /**
     * @param int[] $libraryIds
     * @return string[]
     */
    private function resolveLibraryNames(array $params, array $libraryIds): array
    {
        if ($libraryIds === []) {
            return [];
        }
        $cf = $params['customfields'] ?? [];
        $cacheRaw = is_array($cf) ? (string)($cf['silo_library_names_cache'] ?? '') : '';
        if ($cacheRaw !== '') {
            $decoded = json_decode($cacheRaw, true);
            if (
                is_array($decoded) && isset($decoded['cached_at'], $decoded['names'])
                && (time() - (int)$decoded['cached_at']) < 86400
            ) {
                return array_values(array_intersect_key($decoded['names'], array_flip($libraryIds)));
            }
        }
        try {
            $libs = $this->ctx->client()->listLibraries();
        } catch (SiloApiException $e) {
            return [];
        }
        $byId = [];
        foreach ($libs as $lib) {
            if (isset($lib['id'])) {
                $byId[(int)$lib['id']] = (string)($lib['name'] ?? '');
            }
        }
        $names = [];
        foreach ($libraryIds as $id) {
            if (isset($byId[$id])) {
                $names[] = $byId[$id];
            }
        }
        try {
            $this->ctx->customFields()->write(
                Params::serviceId($params),
                'silo_library_names_cache',
                (string)json_encode(['cached_at' => time(), 'names' => $byId])
            );
        } catch (\Throwable $e) {
            // Non-fatal.
        }
        return $names;
    }
}
