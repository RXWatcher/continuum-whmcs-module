<?php

declare(strict_types=1);

use Continuum\WhmcsModule\Client;
use Continuum\WhmcsModule\ClientEditSync;
use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\DailyReconciler;
use Continuum\WhmcsModule\HomeStore;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/autoload.php';

add_hook('DailyCronJob', 1, function ($vars) {
    // Reconcile every Continuum-backed server every day. There is no
    // per-server opt-out today (no UI for it); add one here if the
    // operator burden ever becomes real.
    $servers = Capsule::table('tblservers')
        ->where('type', 'continuum')
        ->get();
    foreach ($servers as $server) {
        // Server passwords are encrypted; WHMCS decrypts them via decrypt().
        $serverConfig = [
            'serverhostname' => $server->hostname,
            'serverport' => $server->port,
            'serversecure' => $server->secure ? 'on' : '',
            'serverpassword' => decrypt($server->password),
        ];
        try {
            (new DailyReconciler((int)$server->id, $serverConfig))->run();
        } catch (\Throwable $e) {
            logActivity('continuum daily reconcile failed for server ' . $server->id . ': ' . $e->getMessage());
        }
    }
});

// On client email rename: proactively rename the email on every Continuum
// server this WHMCS install talks to. Dual-linkage in Identity::resolve
// would heal lazily on the next module hook, but this keeps Continuum's
// view of the customer in sync immediately.
add_hook('ClientEdit', 1, function ($vars) {
    $newEmail = strtolower(trim((string)($vars['email'] ?? '')));
    $oldEmail = strtolower(trim((string)($vars['olddata']['email'] ?? '')));
    if ($newEmail === '' || $oldEmail === '' || $newEmail === $oldEmail) {
        return;
    }
    $clientId = (int)($vars['userid'] ?? 0);

    // Move the HomeStore pointer to the new email key so multi-server
    // re-home keeps probing the right server for this customer; pointer
    // moves are best-effort and never block the API renames below.
    (new HomeStore())->rename($oldEmail, $newEmail);

    $servers = Capsule::table('tblservers')->where('type', 'continuum')->get();
    foreach ($servers as $server) {
        try {
            $cfg = ServerConfig::fromParams([
                'serverhostname' => $server->hostname,
                'serverport' => $server->port,
                'serversecure' => $server->secure ? 'on' : '',
                'serverpassword' => decrypt($server->password),
            ]);
            $result = (new ClientEditSync(new Client($cfg)))->rename($oldEmail, $newEmail);
            if ($result === ClientEditSync::RESULT_UPDATED) {
                logActivity(
                    "continuum: pushed email rename on server {$server->id} "
                    . "for client {$clientId}: {$oldEmail} → {$newEmail}"
                );
            }
        } catch (\Throwable $e) {
            logActivity(
                "continuum: ClientEdit email-sync failed on server {$server->id} "
                . "for client {$clientId}: " . $e->getMessage()
            );
        }
    }
});
