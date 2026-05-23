<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\Silo\ClientInterface;

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
                throw new SiloApiException('Network error calling Silo: no HTTP status returned');
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
                logActivity('silo: user list >5000 — consider adding email-filter endpoint on Silo side');
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

    /**
     * Note: libraries live at /api/v1/libraries, NOT under /api/v1/admin
     * (verified against a live Silo instance — the /admin path 404s).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLibraries(): array
    {
        $res = $this->jsonRequest('GET', '/api/v1/libraries', null);
        return is_array($res) ? $res : [];
    }

    /** @return array<int, array{id: string, name: string}> */
    public function listUserProfiles(int $userId): array
    {
        $res = $this->jsonRequest('GET', "/api/v1/admin/users/{$userId}/profiles", null);
        return is_array($res) ? $res : [];
    }

    /**
     * Server-wide active playback sessions. The caller filters to the
     * relevant user; rows include `client_ip` (PII) which must never be
     * surfaced to the customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(): array
    {
        $res = $this->jsonRequest('GET', '/api/v1/admin/sessions', null);
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
            throw new \RuntimeException('Failed to encode Silo API request as JSON');
        }

        $res = $this->rawRequest($method, $path, $body);
        $decoded = $res['body'] === '' ? null : json_decode($res['body'], true);

        if ($res['status'] < 100) {
            throw new SiloApiException('Network error calling Silo: no HTTP status returned');
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
        try {
            $res = function_exists('curl_init')
                ? $this->curlRequest($method, $path, $body)
                : $this->streamRequest($method, $path, $body);
        } catch (\Throwable $e) {
            $this->logCall($method, $path, $body, null, $e->getMessage());
            throw $e;
        }
        $this->logCall($method, $path, $body, $res, null);
        return $res;
    }

    /**
     * Record the call in WHMCS's Module Log (Utilities -> Logs -> Module
     * Log) so opaque failures are diagnosable. The API key and any
     * payload password are passed as replace-vars so WHMCS masks them —
     * module logs are sensitive (see README Security Notes).
     *
     * @param array{status: int, body: string, headers: array<string, string[]>}|null $res
     */
    private function logCall(string $method, string $path, ?string $body, ?array $res, ?string $error): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $url = $this->cfg->baseUrl() . $path;
        $request = $method . ' ' . $url . ($body !== null ? "\n" . $body : '');
        $response = $error !== null
            ? 'NETWORK ERROR: ' . $error
            : 'HTTP ' . $res['status'] . "\n" . substr($res['body'], 0, 5000);

        $mask = [$this->cfg->apiKey()];
        if ($body !== null) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['password'])
                && is_string($decoded['password']) && $decoded['password'] !== '') {
                $mask[] = $decoded['password'];
            }
        }

        try {
            logModuleCall('silo', $method . ' ' . $path, $request, $response, '', $mask);
        } catch (\Throwable $e) {
            // Logging must never break the request path.
        }
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string[]>}
     */
    private function curlRequest(string $method, string $path, ?string $body): array
    {
        $ch = curl_init($this->cfg->baseUrl() . $path);
        if ($ch === false) {
            throw new SiloApiException('Network error calling Silo: failed to initialise cURL');
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
            throw new SiloApiException('Network error calling Silo: ' . $err);
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
            throw new SiloApiException(
                'Network error calling Silo: enable PHP cURL or allow_url_fopen'
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
            throw new SiloApiException('Network error calling Silo: ' . $message);
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
            : "Silo returned HTTP {$status}";
        throw new SiloApiException(
            "Silo API error: {$msg}",
            $status,
            is_array($decoded) ? $decoded : null
        );
    }
}
