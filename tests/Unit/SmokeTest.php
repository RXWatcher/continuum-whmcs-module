<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\Handler\ChangePackage;
use Continuum\WhmcsModule\Handler\CreateAccount;
use Continuum\WhmcsModule\Tests\Support\TestCase;

/**
 * Proves the harness is wired: module autoloader, WHMCS function shims,
 * and the Capsule fake are all loadable.
 */
final class SmokeTest extends TestCase
{
    public function testModuleAutoloaderResolvesHandlers(): void
    {
        self::assertTrue(class_exists(CreateAccount::class));
        self::assertTrue(class_exists(ChangePackage::class));
    }

    public function testWhmcsRuntimeIsShimmed(): void
    {
        self::assertTrue(function_exists('localAPI'));
        self::assertTrue(function_exists('logActivity'));
        self::assertTrue(function_exists('decrypt'));
        self::assertTrue(class_exists(\WHMCS\Database\Capsule::class));
    }

    public function testCapsuleFakeReadsSeededRows(): void
    {
        \Continuum\WhmcsModule\Tests\Support\FakeWhmcs::seedTable(
            'tblhosting',
            [['id' => 7, 'domainstatus' => 'Active']]
        );
        $status = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', 7)
            ->value('domainstatus');
        self::assertSame('Active', $status);
        self::assertNull(
            \WHMCS\Database\Capsule::table('tblhosting')->where('id', 999)->value('domainstatus')
        );
    }
}
