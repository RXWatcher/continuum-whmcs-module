<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;

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
            return ['Continuum status' => 'No Continuum user is linked. Run "Reconcile from WHMCS".'];
        }
        try {
            $user = $this->ctx->client()->getUser($userId);
        } catch (ContinuumApiException $e) {
            return ['Continuum status' => 'Continuum unreachable: ' . htmlspecialchars($e->getMessage())];
        }
        $this->ensureLinkage($this->ctx, $params, $userId);
        $deepLink = htmlspecialchars($this->ctx->client()->baseUrlForDeepLink() . "/admin/users/{$userId}");
        $rows = [
            "<table cellspacing='0' cellpadding='4' style='font-size:13px;'>",
            "<tr><td><strong>User ID</strong></td><td>" . (int)$user['id'] . "</td></tr>",
            "<tr><td><strong>Email</strong></td><td>" . htmlspecialchars((string)$user['email']) . "</td></tr>",
            "<tr><td><strong>Enabled</strong></td><td>"
                . (($user['enabled'] ?? false) ? '&#10003; Yes' : '&#10007; No') . "</td></tr>",
            "<tr><td><strong>Role</strong></td><td>" . htmlspecialchars((string)$user['role']) . "</td></tr>",
            "<tr><td><strong>Libraries</strong></td><td>"
                . htmlspecialchars(implode(', ', $user['library_ids'] ?? [])) . "</td></tr>",
            "<tr><td><strong>Stream limit</strong></td><td>" . (int)($user['max_streams'] ?? 0) . "</td></tr>",
            "</table>",
            "<p style='margin-top:0.5rem;'>"
                . "<a href=\"{$deepLink}\" target=\"_blank\" rel=\"noopener\" class=\"btn btn-default\">"
                . "Open in Continuum &rarr;</a></p>",
        ];
        return ['Continuum status' => implode('', $rows)];
    }
}
