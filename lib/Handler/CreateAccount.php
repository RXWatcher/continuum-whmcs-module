<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Handler;

use Silo\WhmcsModule\BadWordList;
use Silo\WhmcsModule\Config\ProductConfig;
use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;
use Silo\WhmcsModule\UsernameGenerator;
use Silo\WhmcsModule\UsernameValidator;
use WHMCS\Database\Capsule;

final class CreateAccount
{
    use HandlerHelpers;

    public function __construct(private HookContext $ctx)
    {
    }

    public function handle(array $params): string
    {
        $serviceId = Params::serviceId($params);

        try {
            $pc = ProductConfig::fromParams($params);
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }

        // Create the custom fields the module needs if the admin hasn't,
        // so the very first provisioning attempt self-heals instead of
        // erroring out.
        $this->ensureCustomFields($params);

        $missing = $this->probeMissing($serviceId);
        if ($missing !== []) {
            return "Custom field '" . implode("', '", $missing) . "' is not declared on this product. See README §Setup.";
        }

        $svcOptions = $this->normaliseConfigurableOptions($params['configoptions'] ?? []);
        $attrs = $this->ctx->mapper()->apply($pc, $svcOptions);

        // Strict resolve: a transient Silo outage during the scan
        // could otherwise look like "no existing user", and we'd then
        // create a duplicate the customer never asked for. Better to
        // fail this hook cleanly and let WHMCS retry it.
        try {
            $existingId = $this->ctx->identity()->resolve($params, strict: true);
        } catch (SiloApiException $e) {
            return $this->humanError($e)
                . ' (refusing to create a duplicate user; retry once Silo is reachable)';
        }
        if ($existingId !== null) {
            try {
                // A CreateAccount always means "this account should exist and
                // be usable". If a prior terminate with delete_on_terminate
                // OFF left the user disabled, the re-order must re-enable it:
                // Silo's updateUser is a partial PATCH (auth/repository.go
                // Update only touches `enabled` when it's present), so an
                // omitted `enabled` leaves the stale disabled state intact.
                $this->ctx->client()->updateUser(
                    $existingId,
                    array_merge($attrs, ['enabled' => true], $this->syncFields($params))
                );
            } catch (SiloApiException $e) {
                return $this->humanError($e);
            }
            $this->ensureLinkage($this->ctx, $params, $existingId);
            $this->rememberHome($params, $this->assignedServerId($params), $existingId);
            return 'success';
        }

        $email = Params::email($params);
        if ($email === '') {
            return 'Client email is required';
        }

        // Multi-server: before creating a fresh user, see if this customer
        // already has a Silo user on another configured server (a
        // retain-on-terminate from a prior order). If so, move this service
        // there and re-link rather than orphaning their history.
        if (ProductConfig::autoRehome($params)) {
            $rehomed = $this->tryRehome($params, $email, $attrs);
            if ($rehomed !== null) {
                return $rehomed; // 'success', or a descriptive failure
            }
        }

        $defaultProfileName = (string)($params['clientsdetails']['firstname'] ?? '');
        if ($defaultProfileName === '') {
            $defaultProfileName = explode('@', $email)[0];
        }

        $resolved = $this->resolveUsername($params, $pc);
        if (isset($resolved['error'])) {
            return $resolved['error'];
        }

        $user = null;
        $username = '';

        $build = fn(string $u) => array_merge($attrs, [
            'email' => $email,
            'username' => $u,
            'password' => Params::password($params),
            'create_default_profile' => $pc->createDefaultProfile(),
            'default_profile_name' => $defaultProfileName,
        ]);

        if (isset($resolved['ok'])) {
            $username = $resolved['ok'];
            try {
                $user = $this->ctx->client()->createUser($build($username));
            } catch (SiloApiException $e) {
                if ($this->isDuplicateUsernameError($e)) {
                    return "Username '{$username}' is already taken. Choose another.";
                }
                $user = $this->recoverFromCreateError($e, $email);
                if ($user === null) {
                    return $this->humanError($e);
                }
                // username was set above
            }
        } else {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $username = UsernameGenerator::generate();
                try {
                    $user = $this->ctx->client()->createUser($build($username));
                    break;
                } catch (SiloApiException $e) {
                    if ($this->isDuplicateUsernameError($e)) {
                        continue;
                    }
                    // Recovery before retrying: a transient failure on
                    // createUser may have actually created the user;
                    // retrying generates a new name and would duplicate.
                    $user = $this->recoverFromCreateError($e, $email);
                    if ($user !== null) {
                        $username = (string)($user['username'] ?? $username);
                        break;
                    }
                    return $this->humanError($e);
                }
            }
            if ($user === null) {
                return 'Username namespace congested — 5 collisions in a row.'
                    . ' Retry the order, or contact support if this persists.';
            }
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId === 0) {
            return 'Silo did not return a user ID; cannot persist linkage';
        }

        $this->ensureLinkage($this->ctx, $params, $userId);
        $this->writeServiceUsername($params, $username);
        $this->rememberHome($params, $this->assignedServerId($params), $userId);
        return 'success';
    }

    /**
     * Re-home: if the customer already has a Silo user on another
     * configured server, move this WHMCS service to that server and
     * re-link the existing user (re-enabled + attributes re-applied).
     *
     * Returns null when no home is found (caller proceeds to create a
     * fresh user — a genuine new customer); 'success' when re-homed; or a
     * descriptive error when a home exists but the move failed (never
     * silently falls back to a fresh account, which would lose history).
     *
     * @param array<string, mixed> $attrs mapped Silo attributes
     */
    private function tryRehome(array $params, string $email, array $attrs): ?string
    {
        $pointer = $this->ctx->homeStore()->get($email);
        $home = $this->ctx->servers()->findHome(
            $email,
            Params::username($params),
            (int)($pointer['serverid'] ?? 0)
        );
        if ($home === null) {
            return null; // genuine new customer (or only on unreachable servers)
        }

        if ($home['serverId'] !== $this->assignedServerId($params)) {
            $moveError = $this->moveServiceToServer($params, $home['serverId']);
            if ($moveError !== null) {
                return $moveError;
            }
        }

        try {
            $home['client']->updateUser($home['userId'], array_merge(
                $attrs,
                ['enabled' => true],
                $this->syncFields($params)
            ));
        } catch (SiloApiException $e) {
            return $this->humanError($e);
        }

        $this->ensureLinkage($this->ctx, $params, $home['userId']);
        if ($home['username'] !== '') {
            $this->writeServiceUsername($params, $home['username']);
        }
        $this->rememberHome($params, $home['serverId'], $home['userId']);
        if (function_exists('logActivity')) {
            logActivity(
                'silo: re-homed service ' . Params::serviceId($params)
                . ' to server ' . $home['serverId'] . ' (Silo user '
                . $home['userId'] . ') to preserve existing account/history'
            );
        }
        return 'success';
    }

    /**
     * Move the WHMCS service to $targetServerId. Tries WHMCS's own
     * UpdateClientProduct first (it does related bookkeeping); some WHMCS
     * versions ignore `serverid` there, so the move is then verified and,
     * if it didn't take, forced with a direct tblhosting write. Returns
     * null on success, or a descriptive error if the move can't be made
     * (so re-home fails loudly rather than orphaning history).
     */
    private function moveServiceToServer(array $params, int $targetServerId): ?string
    {
        $serviceId = Params::serviceId($params);

        try {
            localAPI('UpdateClientProduct', [
                'serviceid' => $serviceId,
                'serverid' => $targetServerId,
            ]);
        } catch (\Throwable $e) {
            // Preferred path unavailable — the direct write below is the
            // guarantee; only its failure is fatal.
        }

        if ($this->currentServer($serviceId) !== $targetServerId) {
            // WHMCS's UpdateClientProduct silently ignored `serverid` on
            // this version. Log the fallback so the next operator
            // wondering "what moved this service" sees it in the audit
            // log rather than chasing a phantom DB edit.
            if (function_exists('logActivity')) {
                logActivity(
                    'silo: UpdateClientProduct did not re-point service '
                    . $serviceId . '; forcing tblhosting.server -> ' . $targetServerId
                );
            }
            try {
                Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->update(['server' => $targetServerId]);
            } catch (\Throwable $e) {
                return 'Re-home failed: could not move service ' . $serviceId
                    . ' to server ' . $targetServerId . ': ' . $e->getMessage();
            }
        }

        if ($this->currentServer($serviceId) !== $targetServerId) {
            return 'Re-home failed: service ' . $serviceId
                . ' did not move to server ' . $targetServerId;
        }
        return null;
    }

    private function currentServer(int $serviceId): int
    {
        try {
            return (int)Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->value('server');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function assignedServerId(array $params): int
    {
        $sid = (int)($params['serverid'] ?? 0);
        if ($sid > 0) {
            return $sid;
        }
        try {
            return (int)Capsule::table('tblhosting')
                ->where('id', Params::serviceId($params))
                ->value('server');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function rememberHome(array $params, int $serverId, int $userId): void
    {
        $email = Params::email($params);
        if ($email !== '' && $serverId > 0 && $userId > 0) {
            $this->ctx->homeStore()->put($email, $serverId, $userId);
        }
    }

    /** @return string[] */
    private function probeMissing(int $serviceId): array
    {
        if ($serviceId === 0) {
            return [];
        }
        try {
            $present = $this->ctx->customFields()->declaredFieldNames($serviceId);
        } catch (\Throwable $e) {
            return [];
        }
        $missing = [];
        foreach (['silo_user_id', 'silo_library_names_cache'] as $required) {
            if (!in_array($required, $present, true)) {
                $missing[] = $required;
            }
        }
        return $missing;
    }

    /** @return array<string, mixed> */
    private function resolveUsername(array $params, ProductConfig $pc): array
    {
        if (!$pc->allowUserChosenUsername()) {
            return ['generate' => true];
        }
        $desired = Params::desiredUsername($params);
        if ($desired === '') {
            return ['generate' => true];
        }
        $validator = new UsernameValidator(BadWordList::resolve(__DIR__ . '/../..'));
        if ($err = $validator->validate($desired)) {
            return ['error' => $err];
        }
        if ($this->ctx->client()->findUserByUsername($desired) !== null) {
            return ['error' => "Username '{$desired}' is already taken. Choose another."];
        }
        return ['ok' => $desired];
    }

    private function writeServiceUsername(array $params, string $username): void
    {
        try {
            localAPI('UpdateClientProduct', [
                'serviceid' => Params::serviceId($params),
                'serviceusername' => $username,
            ]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity(
                    "silo: failed to write back username to WHMCS service "
                    . Params::serviceId($params) . ": " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Idempotency net for createUser. A network blip or 5xx after the
     * user was actually created on Silo's side would otherwise let
     * us re-create (different username, duplicate account, orphan
     * history). On a transient failure (status 0 = network, or 5xx),
     * look the user up by email — if it now exists, treat the original
     * call as having succeeded and link to the recovered record.
     *
     * Returns null if the failure is not recoverable; the caller should
     * then propagate the original error.
     *
     * @return array<string, mixed>|null
     */
    private function recoverFromCreateError(SiloApiException $e, string $email): ?array
    {
        if ($e->httpStatus() !== 0 && $e->httpStatus() < 500) {
            return null;
        }
        if ($email === '') {
            return null;
        }
        try {
            $recovered = $this->ctx->client()->findUserByEmail($email);
        } catch (\Throwable $_) {
            return null;
        }
        if (!is_array($recovered) || !isset($recovered['id'])) {
            return null;
        }
        if (function_exists('logActivity')) {
            logActivity(
                'silo: recovered duplicate-create on transient error for '
                . $email . ' -> user ' . (int)$recovered['id']
            );
        }
        return $recovered;
    }
}
