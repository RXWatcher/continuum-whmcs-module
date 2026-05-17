<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\TestConnection;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class TestConnectionTest extends TestCase
{
    public function testSuccessfulProbe(): void
    {
        $result = (new TestConnection(Context::make(new FakeClient())))->handle();
        self::assertSame(['success' => true, 'error' => ''], $result);
    }

    public function testAuthFailureIsExplained(): void
    {
        $client = new FakeClient();
        $client->listLibrariesError = new ContinuumApiException('401 Unauthorized', 401);

        $result = (new TestConnection(Context::make($client)))->handle();

        self::assertFalse($result['success']);
        self::assertStringContainsString('Authentication failed', $result['error']);
        self::assertStringContainsString('Password / Access Hash', $result['error']);
    }

    public function testServerErrorIsExplained(): void
    {
        $client = new FakeClient();
        $client->listLibrariesError = new ContinuumApiException('boom', 503);

        $result = (new TestConnection(Context::make($client)))->handle();

        self::assertFalse($result['success']);
        self::assertStringContainsString('server error', $result['error']);
    }

    public function testOtherApiErrorPassesMessageThrough(): void
    {
        $client = new FakeClient();
        $client->listLibrariesError = new ContinuumApiException('404 not found', 404);

        $result = (new TestConnection(Context::make($client)))->handle();

        self::assertFalse($result['success']);
        self::assertSame('404 not found', $result['error']);
    }

    public function testNonApiThrowableIsCaught(): void
    {
        $client = new FakeClient();
        $client->listLibrariesError = new \RuntimeException('DNS resolution failed');

        $result = (new TestConnection(Context::make($client)))->handle();

        self::assertFalse($result['success']);
        self::assertSame('DNS resolution failed', $result['error']);
    }
}
