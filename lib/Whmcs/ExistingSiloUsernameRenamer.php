<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Whmcs;

use Silo\WhmcsModule\Silo\ClientInterface;
use Silo\WhmcsModule\UsernameGenerator;

final class ExistingSiloUsernameRenamer
{
    private \Closure $writeWhmcsUsername;
    private \Closure $readWhmcsUsername;
    private \Closure $candidateFactory;

    public function __construct(
        private ClientInterface $client,
        callable $writeWhmcsUsername,
        callable $readWhmcsUsername,
        ?callable $candidateFactory = null,
    ) {
        $this->writeWhmcsUsername = \Closure::fromCallable($writeWhmcsUsername);
        $this->readWhmcsUsername = \Closure::fromCallable($readWhmcsUsername);
        $this->candidateFactory = $candidateFactory === null
            ? static fn(string $first, string $last, int $attempt): ?string =>
                UsernameGenerator::generateFromName($first, $last)
            : \Closure::fromCallable($candidateFactory);
    }

    /** @return array{success:bool,service_id:int,user_id:int,old_username:string,new_username:?string,error:string,critical_mismatch:bool} */
    public function rename(
        int $serviceId,
        int $userId,
        string $oldUsername,
        string $firstName,
        string $lastName,
    ): array {
        $result = $this->result($serviceId, $userId, $oldUsername);
        if ($serviceId <= 0 || $userId <= 0 || $oldUsername === '') {
            $result['error'] = 'Invalid service, user, or existing username';
            return $result;
        }

        try {
            $current = $this->client->getUser($userId);
        } catch (\Throwable $e) {
            $result['error'] = 'Could not read the existing Silo user: ' . $e->getMessage();
            return $result;
        }
        if ((int)($current['id'] ?? 0) !== $userId || (string)($current['username'] ?? '') !== $oldUsername) {
            $result['error'] = 'Existing Silo linkage or username does not match';
            return $result;
        }

        $candidate = null;
        try {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $next = ($this->candidateFactory)($firstName, $lastName, $attempt);
                if (!is_string($next) || $next === '') {
                    $result['error'] = 'Client name cannot produce a username';
                    return $result;
                }
                $occupied = $this->client->findUserByUsername($next);
                if ($occupied === null || (int)($occupied['id'] ?? 0) === $userId) {
                    $candidate = $next;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $result['error'] = 'Could not verify username availability: ' . $e->getMessage();
            return $result;
        }
        if ($candidate === null) {
            $result['error'] = 'No available name-based username after 10 attempts';
            return $result;
        }

        $result['new_username'] = $candidate;
        try {
            $this->client->updateUser($userId, ['username' => $candidate]);
            ($this->writeWhmcsUsername)($serviceId, $candidate);

            $silo = $this->client->getUser($userId);
            $whmcs = (string)($this->readWhmcsUsername)($serviceId);
            if ((string)($silo['username'] ?? '') !== $candidate || $whmcs !== $candidate) {
                throw new \RuntimeException('Username read-back mismatch');
            }
        } catch (\Throwable $e) {
            return $this->compensate($result, $e->getMessage());
        }

        $result['success'] = true;
        return $result;
    }

    /** @param array{success:bool,service_id:int,user_id:int,old_username:string,new_username:?string,error:string,critical_mismatch:bool} $result */
    private function compensate(array $result, string $cause): array
    {
        $errors = [];
        try {
            $this->client->updateUser($result['user_id'], ['username' => $result['old_username']]);
        } catch (\Throwable $e) {
            $errors[] = 'Silo rollback failed: ' . $e->getMessage();
        }
        try {
            ($this->writeWhmcsUsername)($result['service_id'], $result['old_username']);
        } catch (\Throwable $e) {
            $errors[] = 'WHMCS rollback failed: ' . $e->getMessage();
        }

        try {
            $silo = $this->client->getUser($result['user_id']);
            if ((string)($silo['username'] ?? '') !== $result['old_username']) {
                $errors[] = 'Silo rollback verification failed';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Silo rollback verification failed: ' . $e->getMessage();
        }
        try {
            if ((string)($this->readWhmcsUsername)($result['service_id']) !== $result['old_username']) {
                $errors[] = 'WHMCS rollback verification failed';
            }
        } catch (\Throwable $e) {
            $errors[] = 'WHMCS rollback verification failed: ' . $e->getMessage();
        }

        $result['critical_mismatch'] = $errors !== [];
        $result['error'] = $cause . ($errors === [] ? '; old username restored' : '; ' . implode('; ', $errors));
        return $result;
    }

    /** @return array{success:bool,service_id:int,user_id:int,old_username:string,new_username:?string,error:string,critical_mismatch:bool} */
    private function result(int $serviceId, int $userId, string $oldUsername): array
    {
        return [
            'success' => false,
            'service_id' => $serviceId,
            'user_id' => $userId,
            'old_username' => $oldUsername,
            'new_username' => null,
            'error' => '',
            'critical_mismatch' => false,
        ];
    }
}
