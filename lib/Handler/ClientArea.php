<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;
use Continuum\WhmcsModule\PlaybackQuality;

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
                'error' => 'Your Continuum account is not yet linked. Contact support.',
            ]];
        }
        $this->ensureLinkage($this->ctx, $params, $userId);

        $vars = [
            'status' => 'active',
            'stream_limit' => null,
            'quality' => 'Unrestricted',
            'library_names' => [],
            'last_seen_relative' => 'never',
            'login_url' => $this->ctx->client()->baseUrlForDeepLink() . '/',
        ];

        try {
            $user = $this->ctx->client()->getUser($userId);
            $vars['stream_limit'] = (int)($user['max_streams'] ?? 0);
            $vars['quality'] = $this->humanQuality((string)($user['max_playback_quality'] ?? ''));
            if (!($user['enabled'] ?? true)) {
                $vars['status'] = 'suspended';
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
        } catch (ContinuumApiException $e) {
            $vars['status'] = 'active (status unavailable)';
        }

        return ['templatefile' => 'clientarea', 'vars' => $vars];
    }

    private function humanQuality(string $q): string
    {
        // $q comes from Continuum ('', '1080p', '2160p').
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
        $cacheRaw = is_array($cf) ? (string)($cf['continuum_library_names_cache'] ?? '') : '';
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
        } catch (ContinuumApiException $e) {
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
                'continuum_library_names_cache',
                (string)json_encode(['cached_at' => time(), 'names' => $byId])
            );
        } catch (\Throwable $e) {
            // Non-fatal.
        }
        return $names;
    }
}
