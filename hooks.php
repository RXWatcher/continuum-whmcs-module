<?php

declare(strict_types=1);

use Continuum\WhmcsModule\Client;
use Continuum\WhmcsModule\ClientEditSync;
use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\DailyReconciler;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/vendor/autoload.php';

add_hook('DailyCronJob', 1, function ($vars) {
    // Walk all Continuum-backed servers with reconcile_daily enabled.
    $servers = Capsule::table('tblservers')
        ->where('type', 'continuum')
        ->get();
    foreach ($servers as $server) {
        // Server passwords are encrypted; WHMCS decrypts them via decrypt().
        // The exact accessor is version-specific (Layer 12 item 6).
        $serverConfig = [
            'serverhostname' => $server->hostname,
            'serversecure' => $server->secure ? 'on' : '',
            'serverpassword' => decrypt($server->password),
            'reconcile_daily' => 'yes',  // we already filtered above
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
    $servers = Capsule::table('tblservers')->where('type', 'continuum')->get();
    foreach ($servers as $server) {
        try {
            $cfg = ServerConfig::fromParams([
                'serverhostname' => $server->hostname,
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
