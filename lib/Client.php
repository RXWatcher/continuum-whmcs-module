<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Continuum\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class Client implements ClientInterface
{
    private ServerConfig $cfg;
    private GuzzleClient $http;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $userListCache = null;
    private bool $warnedAboveThreshold = false;

    public function __construct(ServerConfig $cfg, ?GuzzleClient $http = null)
    {
        $this->cfg = $cfg;
        $this->http = $http ?? new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function createUser(array $payload): array
    {
        return $this->jsonRequest('POST', '/api/v1/admin/users', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function updateUser(int $userId, array $payload): array
    {
        return $this->jsonRequest('PUT', "/api/v1/admin/users/{$userId}", $payload);
    }

    public function deleteUser(int $userId): void
    {
        $this->jsonRequest('DELETE', "/api/v1/admin/users/{$userId}", null);
    }

    public function getUser(int $userId): array
    {
        return $this->jsonRequest('GET', "/api/v1/admin/users/{$userId}", null);
    }

    public function findUserByEmail(string $email): ?array
    {
        $needle = strtolower(trim($email));
        if ($needle === '') {
            return null;
        }
        foreach ($this->loadUserList() as $user) {
            if (is_array($user) && strtolower((string)($user['email'] ?? '')) === $needle) {
                return $user;
            }
        }
        return null;
    }

    public function findUserByUsername(string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        foreach ($this->loadUserList() as $user) {
            if (is_array($user) && ($user['username'] ?? null) === $username) {
                return $user;
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadUserList(): array
    {
        if ($this->userListCache !== null) {
            return $this->userListCache;
        }
        $all = [];
        $path = '/api/v1/admin/users';
        while ($path !== null) {
            try {
                $res = $this->http->request('GET', $this->cfg->baseUrl() . $path, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->cfg->apiKey(),
                        'Accept' => 'application/json',
                    ],
                    'http_errors' => false,
                ]);
            } catch (GuzzleException | \RuntimeException $e) {
                throw new ContinuumApiException(
                    'Network error calling Continuum: ' . $e->getMessage(),
                    0,
                    null,
                    $e
                );
            }
            $status = $res->getStatusCode();
            $body = (string)$res->getBody();
            $decoded = $body === '' ? null : json_decode($body, true);
            if ($status >= 400) {
                $msg = is_array($decoded) && isset($decoded['message'])
                    ? $decoded['message']
                    : "Continuum returned HTTP {$status}";
                throw new ContinuumApiException(
                    "Continuum API error: {$msg}",
                    $status,
                    is_array($decoded) ? $decoded : null
                );
            }
            if (!is_array($decoded)) {
                break;
            }
            $all = array_merge($all, $decoded);
            $path = $this->nextPagePath($res->getHeaderLine('Link'));
        }
        if (count($all) > 5000 && !$this->warnedAboveThreshold) {
            $this->warnedAboveThreshold = true;
            if (function_exists('logActivity')) {
                logActivity('continuum: user list >5000 — consider adding email-filter endpoint on Continuum side');
            }
        }
        $this->userListCache = $all;
        return $all;
    }

    private function nextPagePath(string $linkHeader): ?string
    {
        if ($linkHeader === '' || !preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $m)) {
            return null;
        }
        $path = parse_url($m[1], PHP_URL_PATH);
        $query = parse_url($m[1], PHP_URL_QUERY);
        return $query ? "{$path}?{$query}" : $path;
    }

    /** @return array<int, array<string, mixed>> */
    public function listLibraries(): array
    {
        $res = $this->jsonRequest('GET', '/api/v1/admin/libraries', null);
        return is_array($res) ? $res : [];
    }

    public function baseUrlForDeepLink(): string
    {
        return $this->cfg->baseUrl();
    }

    /** @param array<string, mixed>|null $payload */
    private function jsonRequest(string $method, string $path, ?array $payload): array
    {
        $opts = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->cfg->apiKey(),
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($payload !== null) {
            $opts['headers']['Content-Type'] = 'application/json';
            $opts['body'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        try {
            /** @var ResponseInterface $res */
            $res = $this->http->request($method, $this->cfg->baseUrl() . $path, $opts);
        } catch (GuzzleException | \RuntimeException $e) {
            throw new ContinuumApiException(
                "Network error calling Continuum: " . $e->getMessage(),
                0,
                null,
                $e
            );
        }

        $status = $res->getStatusCode();
        $body = (string)$res->getBody();
        $decoded = $body === '' ? null : json_decode($body, true);

        if ($status >= 400) {
            $msg = is_array($decoded) && isset($decoded['message'])
                ? $decoded['message']
                : "Continuum returned HTTP {$status}";
            throw new ContinuumApiException(
                "Continuum API error: {$msg}",
                $status,
                is_array($decoded) ? $decoded : null
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
