<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Continuum\ClientInterface;

final class Client implements ClientInterface
{
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private ServerConfig $cfg;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $userListCache = null;
    private bool $warnedAboveThreshold = false;

    public function __construct(ServerConfig $cfg)
    {
        $this->cfg = $cfg;
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
            $res = $this->rawRequest('GET', $path, null);
            $decoded = $res['body'] === '' ? null : json_decode($res['body'], true);
            if ($res['status'] < 100) {
                throw new ContinuumApiException('Network error calling Continuum: no HTTP status returned');
            }
            if ($res['status'] >= 400) {
                $this->throwApiError($res['status'], $decoded);
            }
            if (!is_array($decoded)) {
                break;
            }
            $all = array_merge($all, $decoded);
            $path = $this->nextPagePath($this->headerLine($res['headers'], 'Link'));
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
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode Continuum API request as JSON');
        }

        $res = $this->rawRequest($method, $path, $body);
        $decoded = $res['body'] === '' ? null : json_decode($res['body'], true);

        if ($res['status'] < 100) {
            throw new ContinuumApiException('Network error calling Continuum: no HTTP status returned');
        }
        if ($res['status'] >= 400) {
            $this->throwApiError($res['status'], $decoded);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string[]>}
     */
    private function rawRequest(string $method, string $path, ?string $body): array
    {
        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $path, $body);
        }

        return $this->streamRequest($method, $path, $body);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string[]>}
     */
    private function curlRequest(string $method, string $path, ?string $body): array
    {
        $ch = curl_init($this->cfg->baseUrl() . $path);
        if ($ch === false) {
            throw new ContinuumApiException('Network error calling Continuum: failed to initialise cURL');
        }

        $headers = $this->requestHeaders($body !== null);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ContinuumApiException('Network error calling Continuum: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $this->parseHeaders(substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        ];
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string[]>}
     */
    private function streamRequest(string $method, string $path, ?string $body): array
    {
        if (!ini_get('allow_url_fopen')) {
            throw new ContinuumApiException(
                'Network error calling Continuum: enable PHP cURL or allow_url_fopen'
            );
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->requestHeaders($body !== null)),
                'ignore_errors' => true,
                'timeout' => self::TIMEOUT_SECONDS,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }

        $previousError = error_get_last();
        $responseBody = @file_get_contents($this->cfg->baseUrl() . $path, false, stream_context_create($opts));
        if ($responseBody === false) {
            $err = error_get_last();
            $message = is_array($err) && $err !== $previousError ? (string)$err['message'] : 'request failed';
            throw new ContinuumApiException('Network error calling Continuum: ' . $message);
        }

        /** @var array<int, string> $http_response_header */
        $headers = $http_response_header ?? [];

        return [
            'status' => $this->statusFromHeaders($headers),
            'headers' => $this->parseHeaders(implode("\r\n", $headers)),
            'body' => $responseBody,
        ];
    }

    /** @return string[] */
    private function requestHeaders(bool $hasBody): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->cfg->apiKey(),
            'Accept: application/json',
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }
        return $headers;
    }

    /** @return array<string, string[]> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }
        return $headers;
    }

    /** @param array<int, string> $headers */
    private function statusFromHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return $status;
    }

    /** @param array<string, string[]> $headers */
    private function headerLine(array $headers, string $name): string
    {
        return implode(', ', $headers[strtolower($name)] ?? []);
    }

    private function throwApiError(int $status, mixed $decoded): void
    {
        $msg = is_array($decoded) && isset($decoded['message'])
            ? $decoded['message']
            : "Continuum returned HTTP {$status}";
        throw new ContinuumApiException(
            "Continuum API error: {$msg}",
            $status,
            is_array($decoded) ? $decoded : null
        );
    }
}
