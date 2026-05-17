<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\AdminServicesTab;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class AdminServicesTabTest extends TestCase
{
    /** @return array<string, mixed> */
    private function params(): array
    {
        return Context::params(['customfields' => ['continuum_user_id' => '7']]);
    }

    public function testUnlinkedServiceShowsReconcileHint(): void
    {
        $out = (new AdminServicesTab(Context::make(new FakeClient())))
            ->handle(Context::params(['username' => '', 'clientsdetails' => ['email' => '']]));

        self::assertSame(
            ['Continuum status' => 'No Continuum user is linked. Run "Reconcile from WHMCS".'],
            $out
        );
    }

    public function testUnreachableApiIsReported(): void
    {
        // Resolve via email (tier 2, no getUser) so the handler's own
        // getUser call is what fails.
        $client = new FakeClient();
        $client->usersByEmail['jane@example.com'] = ['id' => 7];
        $client->getUserError = new ContinuumApiException('timeout', 504);

        $out = (new AdminServicesTab(Context::make($client)))->handle(Context::params());

        self::assertStringContainsString('Continuum unreachable', $out['Continuum status']);
    }

    public function testRendersUserSummaryAndDeepLink(): void
    {
        $client = new FakeClient();
        $client->usersById[7] = [
            'id' => 7,
            'email' => 'jane@example.com',
            'enabled' => true,
            'role' => 'user',
            'library_ids' => [1, 2],
            'max_streams' => 6,
        ];

        $html = (new AdminServicesTab(Context::make($client)))->handle($this->params())['Continuum status'];

        self::assertStringContainsString('<strong>User ID</strong>', $html);
        self::assertStringContainsString('jane@example.com', $html);
        self::assertStringContainsString('&#10003; Yes', $html); // enabled tick
        self::assertStringContainsString('https://continuum.test/admin/users/7', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }
}
