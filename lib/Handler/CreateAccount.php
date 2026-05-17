<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\BadWordList;
use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\HookContext;
use Continuum\WhmcsModule\Identity\Params;
use Continuum\WhmcsModule\UsernameGenerator;
use Continuum\WhmcsModule\UsernameValidator;

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

        $existingId = $this->ctx->identity()->resolve($params);
        if ($existingId !== null) {
            try {
                $this->ctx->client()->updateUser($existingId, array_merge($attrs, $this->syncFields($params)));
            } catch (ContinuumApiException $e) {
                return $this->humanError($e);
            }
            $this->ensureLinkage($this->ctx, $params, $existingId);
            return 'success';
        }

        $email = Params::email($params);
        if ($email === '') {
            return 'Client email is required';
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
            } catch (ContinuumApiException $e) {
                if ($this->isDuplicateUsernameError($e)) {
                    return "Username '{$username}' is already taken. Choose another.";
                }
                return $this->humanError($e);
            }
        } else {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $username = UsernameGenerator::generate();
                try {
                    $user = $this->ctx->client()->createUser($build($username));
                    break;
                } catch (ContinuumApiException $e) {
                    if ($this->isDuplicateUsernameError($e)) {
                        continue;
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
            return 'Continuum did not return a user ID; cannot persist linkage';
        }

        $this->ensureLinkage($this->ctx, $params, $userId);
        $this->writeServiceUsername($params, $username);
        return 'success';
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
        foreach (['continuum_user_id', 'continuum_library_names_cache'] as $required) {
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
                    "continuum: failed to write back username to WHMCS service "
                    . Params::serviceId($params) . ": " . $e->getMessage()
                );
            }
        }
    }
}
