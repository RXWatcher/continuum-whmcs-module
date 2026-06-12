<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\Tests\Support\TestCase;

final class ServerConfigTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function cfg(array $extra): ServerConfig
    {
        return ServerConfig::fromParams(array_merge([
            'serverhostname' => 'host.example',
            'serverpassword' => 'api-key',
            'serversecure' => '',
        ], $extra));
    }

    public function testInsecureBaseUrlAllowedForLoopback(): void
    {
        // http is permitted to local/private hosts (dev, LAN backends).
        $c = $this->cfg(['serverhostname' => 'localhost']);
        self::assertSame('http://localhost', $c->baseUrl());
        self::assertSame('api-key', $c->apiKey());
    }

    public function testInsecureBaseUrlAllowedForPrivateRangeHosts(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.0.1', '169.254.0.1'] as $host) {
            self::assertSame(
                "http://{$host}",
                $this->cfg(['serverhostname' => $host])->baseUrl(),
                "{$host} is private/loopback — http allowed"
            );
        }
    }

    public function testInsecureHttpToPublicHostIsRejected(): void
    {
        // Sending the admin Bearer key + user passwords in cleartext to a
        // public host is a credential-exposure risk: refuse it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');
        $this->cfg(['serverhostname' => 'silo.example.com']);
    }

    public function testInsecureHttpToPublicIpIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cfg(['serverhostname' => '8.8.8.8']);
    }

    public function testSecureBaseUrl(): void
    {
        self::assertSame('https://host.example', $this->cfg(['serversecure' => 'on'])->baseUrl());
    }

    public function testSchemeAndPathAreStrippedFromHostname(): void
    {
        self::assertSame(
            'http://localhost',
            $this->cfg(['serverhostname' => 'https://localhost/admin'])->baseUrl()
        );
    }

    public function testPortEmbeddedInHostnameIsHonoured(): void
    {
        self::assertSame(
            'https://host.example:8443',
            $this->cfg(['serverhostname' => 'host.example:8443', 'serversecure' => 'on'])->baseUrl()
        );
    }

    public function testExplicitServerPortWinsOverEmbedded(): void
    {
        self::assertSame(
            'http://localhost:8080',
            $this->cfg(['serverhostname' => 'localhost:9000', 'serverport' => '8080'])->baseUrl()
        );
    }

    public function testDefaultPortIsOmitted(): void
    {
        self::assertSame(
            'http://localhost',
            $this->cfg(['serverhostname' => 'localhost', 'serverport' => '80'])->baseUrl()
        );
    }

    public function testMissingHostnameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerConfig::fromParams(['serverhostname' => '', 'serverpassword' => 'k']);
    }

    public function testMissingApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerConfig::fromParams(['serverhostname' => 'host.example', 'serverpassword' => '']);
    }
}
