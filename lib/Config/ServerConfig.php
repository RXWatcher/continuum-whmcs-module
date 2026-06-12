<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Config;

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
        // Refuse to send the admin Bearer key and user passwords in
        // cleartext to a public host. http is tolerated only for
        // loopback/private-range backends (local dev, LAN), where there's
        // no untrusted network between WHMCS and Silo.
        if (!$secure && !self::isLocalOrPrivateHost($host)) {
            throw new \InvalidArgumentException(
                "Refusing to use plaintext HTTP to public host '{$host}': enable"
                . ' the server\'s Secure (SSL/TLS) option so the admin API key and'
                . ' passwords are sent over HTTPS.'
            );
        }
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

    /**
     * Hosts for which plaintext HTTP is acceptable: there is no untrusted
     * network between WHMCS and the Silo backend. Covers localhost, IPv4
     * loopback/RFC-1918/link-local, and IPv6 loopback/ULA.
     */
    private static function isLocalOrPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t[]"));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // Private (10/8, 172.16/12, 192.168/16) and reserved
            // (loopback 127/8, link-local 169.254/16) ranges are NOT
            // routable on the public internet.
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ::1 loopback and fc00::/7 unique-local addresses.
            return $host === '::1' || str_starts_with($host, 'fc') || str_starts_with($host, 'fd');
        }

        return false;
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
