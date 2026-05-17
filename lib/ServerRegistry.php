<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Continuum\ClientInterface;
use WHMCS\Database\Capsule;

/**
 * Cross-server lookup over every active continuum-typed WHMCS server.
 *
 * Resolution in the per-service hooks is single-server (whatever WHMCS
 * assigned). When a returning customer's new order is routed to a
 * different server, their existing Continuum user — kept via
 * delete_on_terminate=OFF — lives elsewhere. This locates that server so
 * CreateAccount can re-home the service to it instead of creating a fresh
 * account and orphaning the history.
 *
 * The Continuum client is built through an injectable factory so tests
 * can supply fakes; production builds a real Client per server (the same
 * tblservers + decrypt() pattern hooks.php already uses).
 */
final class ServerRegistry
{
    /** @var callable(ServerConfig):ClientInterface */
    private $factory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->factory = $clientFactory
            ?? static fn(ServerConfig $cfg): ClientInterface => new Client($cfg);
    }

    /**
     * Locate the server hosting the Continuum user for $email (then,
     * failing that, $username). $preferServerId is probed first — pass the
     * cached HomeStore pointer to make this deterministic and cheap.
     *
     * @return array{serverId:int, userId:int, username:string, client:ClientInterface}|null
     */
    public function findHome(string $email, string $username = '', int $preferServerId = 0): ?array
    {
        $email = strtolower(trim($email));
        $servers = $this->activeServers();
        // Probe the preferred (cached) server first.
        usort(
            $servers,
            static fn($a, $b): int =>
                ((int)$b->id === $preferServerId ? 1 : 0) <=> ((int)$a->id === $preferServerId ? 1 : 0)
        );

        $byUsername = null;
        foreach ($servers as $s) {
            try {
                $client = ($this->factory)($this->configFor($s));
            } catch (\Throwable $e) {
                continue; // unconfigurable server — skip, never abort the scan
            }
            try {
                if ($email !== '') {
                    $u = $client->findUserByEmail($email);
                    if (is_array($u) && isset($u['id'])) {
                        return [
                            'serverId' => (int)$s->id,
                            'userId' => (int)$u['id'],
                            'username' => (string)($u['username'] ?? ''),
                            'client' => $client,
                        ];
                    }
                }
                if ($byUsername === null && $username !== '') {
                    $u = $client->findUserByUsername($username);
                    if (is_array($u) && isset($u['id'])) {
                        $byUsername = [
                            'serverId' => (int)$s->id,
                            'userId' => (int)$u['id'],
                            'username' => (string)($u['username'] ?? $username),
                            'client' => $client,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                continue; // unreachable server — keep scanning the rest
            }
        }
        return $byUsername;
    }

    /** @return array<int, object> active (non-disabled) continuum servers */
    private function activeServers(): array
    {
        try {
            $rows = Capsule::table('tblservers')->where('type', 'continuum')->get();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if ((int)($r->disabled ?? 0) === 1) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    private function configFor(object $s): ServerConfig
    {
        $password = $s->password ?? '';
        return ServerConfig::fromParams([
            'serverhostname' => $s->hostname ?? '',
            'serverport' => $s->port ?? '',
            'serversecure' => ($s->secure ?? '') ? 'on' : '',
            'serverpassword' => function_exists('decrypt') ? decrypt($password) : $password,
        ]);
    }
}
