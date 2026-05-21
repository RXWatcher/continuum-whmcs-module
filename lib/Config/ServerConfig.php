<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Config;

final class ServerConfig
{
    private string $baseUrl;
    private string $apiKey;

    private function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
    }

    /**
     * @param array<string, mixed> $params raw $params passed by WHMCS into module hooks
     */
    public static function fromParams(array $params): self
    {
        $host = trim((string)($params['serverhostname'] ?? ''));
        // Tolerate hostnames that include a scheme, path, or :port —
        // older installs jammed the port into this field because the
        // module exposed no port field until now. Reduce to a bare host.
        $host = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $host) ?? $host;
        $host = trim(explode('/', $host, 2)[0]);

        $embeddedPort = 0;
        if (preg_match('/^(?<host>[^:]+):(?<port>\d+)$/', $host, $m) === 1) {
            $host = $m['host'];
            $embeddedPort = (int)$m['port'];
        }
        if ($host === '') {
            throw new \InvalidArgumentException('Server hostname is required');
        }

        $apiKey = trim((string)($params['serverpassword'] ?? ''));
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Server admin API key (Password / Access Hash) is required');
        }
        $secure = ($params['serversecure'] ?? '') !== '';
        $scheme = $secure ? 'https://' : 'http://';
        $defaultPort = $secure ? 443 : 80;

        // Explicit WHMCS server-form port wins; otherwise fall back to
        // any port embedded in the hostname field.
        $port = (int)trim((string)($params['serverport'] ?? ''));
        if ($port === 0) {
            $port = $embeddedPort;
        }
        $portSuffix = ($port > 0 && $port !== $defaultPort) ? ':' . $port : '';

        return new self($scheme . $host . $portSuffix, $apiKey);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }
}
