<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Tests\Support\TestCase;

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

    public function testInsecureBaseUrl(): void
    {
        $c = $this->cfg([]);
        self::assertSame('http://host.example', $c->baseUrl());
        self::assertSame('api-key', $c->apiKey());
        self::assertFalse($c->reconcileDaily());
    }

    public function testSecureBaseUrl(): void
    {
        self::assertSame('https://host.example', $this->cfg(['serversecure' => 'on'])->baseUrl());
    }

    public function testSchemeAndPathAreStrippedFromHostname(): void
    {
        self::assertSame(
            'http://host.example',
            $this->cfg(['serverhostname' => 'https://host.example/admin'])->baseUrl()
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
            'http://host.example:8080',
            $this->cfg(['serverhostname' => 'host.example:9000', 'serverport' => '8080'])->baseUrl()
        );
    }

    public function testDefaultPortIsOmitted(): void
    {
        self::assertSame(
            'http://host.example',
            $this->cfg(['serverport' => '80'])->baseUrl()
        );
    }

    public function testReconcileDailyFlag(): void
    {
        self::assertTrue($this->cfg(['reconcile_daily' => 'yes'])->reconcileDaily());
        self::assertFalse($this->cfg(['reconcile_daily' => 'no'])->reconcileDaily());
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
