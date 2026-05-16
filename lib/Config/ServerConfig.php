<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Config;

final class ServerConfig
{
    private string $baseUrl;
    private string $apiKey;
    private bool $reconcileDaily;

    private function __construct(string $baseUrl, string $apiKey, bool $reconcileDaily)
    {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->reconcileDaily = $reconcileDaily;
    }

    /**
     * @param array<string, mixed> $params raw $params passed by WHMCS into module hooks
     */
    public static function fromParams(array $params): self
    {
        $host = trim((string)($params['serverhostname'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('Server hostname is required');
        }
        $apiKey = trim((string)($params['serverpassword'] ?? ''));
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Server admin API key (Password / Access Hash) is required');
        }
        $secure = ($params['serversecure'] ?? '') !== '';
        $reconcileDaily = ($params['reconcile_daily'] ?? 'no') === 'yes';
        return new self(
            ($secure ? 'https://' : 'http://') . $host,
            $apiKey,
            $reconcileDaily,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function reconcileDaily(): bool
    {
        return $this->reconcileDaily;
    }
}
