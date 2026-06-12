<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\Client;
use Silo\WhmcsModule\Tests\Support\TestCase;

/**
 * The Module Log records Silo response bodies for diagnosability. The
 * sessions endpoint returns customer IP addresses (PII the module is
 * otherwise careful never to surface) — those must be redacted before
 * the body reaches the log.
 */
final class ClientLogRedactionTest extends TestCase
{
    public function testClientIpIsRedactedFromLoggedBody(): void
    {
        $body = json_encode([
            ['user_id' => 5, 'client_ip' => '203.0.113.9', 'media_title' => 'Dune'],
            ['user_id' => 6, 'client_ip' => '198.51.100.4'],
        ]);

        $redacted = Client::redactResponseBodyForLog($body);

        self::assertStringNotContainsString('203.0.113.9', $redacted);
        self::assertStringNotContainsString('198.51.100.4', $redacted);
        self::assertStringContainsString('[redacted]', $redacted);
        self::assertStringContainsString('Dune', $redacted, 'non-PII fields are preserved');
    }

    public function testIpAddressFieldIsAlsoRedacted(): void
    {
        $redacted = Client::redactResponseBodyForLog('{"ip_address":"10.1.2.3","x":1}');
        self::assertStringNotContainsString('10.1.2.3', $redacted);
    }

    public function testNonPiiBodyIsUnchanged(): void
    {
        $body = '{"id":42,"username":"bob","max_streams":6}';
        self::assertSame($body, Client::redactResponseBodyForLog($body));
    }
}
