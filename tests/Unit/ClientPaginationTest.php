<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\Client;
use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\SiloApiException;
use Silo\WhmcsModule\Tests\Support\TestCase;

/**
 * loadUserList() follows the `Link: rel="next"` chain. A Silo that keeps
 * advertising a next page (a bug, or a Link header that loops back on
 * itself) must not hang the WHMCS request forever — the walk is bounded
 * and fails closed.
 */
final class ClientPaginationTest extends TestCase
{
    public function testPaginationStopsAtTheHardCapAndFailsClosed(): void
    {
        $client = new class (ServerConfig::fromParams([
            'serverhostname' => 'localhost',
            'serverpassword' => 'k',
            'serversecure' => '',
        ])) extends Client {
            public int $pages = 0;
            protected function rawRequest(string $method, string $path, ?string $body): array
            {
                $this->pages++;
                // Always advertise another page → infinite without a cap.
                return [
                    'status' => 200,
                    'body' => json_encode([['id' => $this->pages, 'email' => 'x@example.com']]),
                    'headers' => ['link' => [
                        '<https://localhost/api/v1/admin/users?page=' . ($this->pages + 1) . '>; rel="next"',
                    ]],
                ];
            }
        };

        try {
            $client->findUserByUsername('nobody');
            self::fail('expected the paginated walk to fail closed at the cap');
        } catch (SiloApiException $e) {
            self::assertStringContainsString('page', strtolower($e->getMessage()));
        }

        self::assertLessThanOrEqual(1000, $client->pages, 'walk is bounded');
        self::assertGreaterThan(1, $client->pages, 'it did paginate before giving up');
    }
}
