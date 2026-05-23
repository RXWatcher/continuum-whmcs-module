<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\PlaybackQuality;

/**
 * Backs the "Silo status" admin tab on a service. Inline HTML is
 * required by the WHMCS AdminServicesTabFields contract — the hook
 * returns a `[label => html]` array; there is no template mechanism for
 * this surface, hence the string concat.
 *
 * Field set mirrors what the customer sees in the client area so that
 * an admin reconciling a service can verify limits at a glance without
 * clicking through to Silo.
 */
final class AdminServicesTab
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): array
    {
        $userId = $this->ctx->identity()->resolve($params);
        if ($userId === null) {
            return ['Silo status' => 'No Silo user is linked. Run "Reconcile from WHMCS".'];
        }
        try {
            $user = $this->ctx->client()->getUser($userId);
        } catch (SiloApiException $e) {
            return ['Silo status' => 'Silo unreachable: ' . htmlspecialchars($e->getMessage())];
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        $deepLink = htmlspecialchars($this->ctx->client()->baseUrlForDeepLink() . "/admin/users/{$userId}");

        // null library_ids means unrestricted access; render explicitly
        // so an admin doesn't mistake a blank cell for "no libraries".
        $libIds = $user['library_ids'] ?? null;
        $librariesLabel = $libIds === null
            ? '<em>All libraries (unrestricted)</em>'
            : htmlspecialchars(implode(', ', array_map('strval', (array)$libIds)));

        $rows = [
            "<table cellspacing='0' cellpadding='4' style='font-size:13px;'>",
            $this->row('User ID', (string)(int)$user['id']),
            $this->row('Username', htmlspecialchars((string)($user['username'] ?? ''))),
            $this->row('Email', htmlspecialchars((string)($user['email'] ?? ''))),
            $this->row('Enabled', ($user['enabled'] ?? false) ? '&#10003; Yes' : '&#10007; No'),
            $this->row('Role', htmlspecialchars((string)($user['role'] ?? ''))),
            $this->row('Libraries', $librariesLabel),
            $this->row('Stream limit', (string)(int)($user['max_streams'] ?? 0)),
            $this->row('Transcode limit', (string)(int)($user['max_transcodes'] ?? 0)),
            $this->row('Profile limit', (string)(int)($user['max_profiles'] ?? 0)),
            $this->row('Downloads', ($user['download_allowed'] ?? false) ? 'Allowed' : 'Not allowed'),
            $this->row(
                'Download transcode',
                ($user['download_transcode_allowed'] ?? false) ? 'Allowed' : 'Not allowed'
            ),
            $this->row(
                'Max playback quality',
                htmlspecialchars(PlaybackQuality::human((string)($user['max_playback_quality'] ?? '')))
            ),
            $this->row('Last seen', htmlspecialchars($this->formatTime((string)($user['last_active_at'] ?? '')))),
            "</table>",
            "<p style='margin-top:0.5rem;'>"
                . "<a href=\"{$deepLink}\" target=\"_blank\" rel=\"noopener\" class=\"btn btn-default\">"
                . "Open in Silo &rarr;</a></p>",
        ];
        return ['Silo status' => implode('', $rows)];
    }

    private function row(string $label, string $valueHtml): string
    {
        return "<tr><td><strong>{$label}</strong></td><td>{$valueHtml}</td></tr>";
    }

    private function formatTime(string $iso): string
    {
        if ($iso === '') {
            return 'never';
        }
        try {
            return (new \DateTimeImmutable($iso))->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return $iso;
        }
    }
}
