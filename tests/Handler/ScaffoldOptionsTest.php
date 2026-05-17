<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Handler;

use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Handler\ScaffoldOptions;
use Continuum\WhmcsModule\Tests\Support\Context;
use Continuum\WhmcsModule\Tests\Support\FakeClient;
use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class ScaffoldOptionsTest extends TestCase
{
    private function seedSchema(): void
    {
        FakeWhmcs::seedTable('tblcurrencies', [['id' => 1]]);
        FakeWhmcs::seedTable('tblproducts', [['id' => 10, 'servertype' => 'continuum']]);
    }

    public function testScaffoldsSuccessfullyWithLiveLibraries(): void
    {
        $this->seedSchema();
        $client = new FakeClient();
        $client->libraries = [['id' => 1, 'name' => 'Movies']];

        $result = (new ScaffoldOptions(Context::make($client)))->handle();

        self::assertSame('success', $result);
        self::assertCount(1, FakeWhmcs::rows('tblproductconfiggroups'));
        $names = array_column(FakeWhmcs::rows('tblproductconfigoptions'), 'optionname');
        self::assertContains('Library 1', $names, 'per-library opt-in scaffolded for the live library');
        self::assertNotEmpty(FakeWhmcs::$activityLog);
    }

    public function testLibraryListFailureIsNonFatal(): void
    {
        $this->seedSchema();
        $client = new FakeClient();
        $client->listLibrariesError = new ContinuumApiException('libs down', 503);

        $result = (new ScaffoldOptions(Context::make($client)))->handle();

        self::assertSame('success', $result);
        // Base options still created; just no per-library opt-ins.
        $names = array_column(FakeWhmcs::rows('tblproductconfigoptions'), 'optionname');
        self::assertContains('Extra Streams', $names);
        self::assertSame([], array_filter($names, static fn($n) => str_starts_with((string)$n, 'Library ')));
    }

    public function testScaffolderFailureIsReported(): void
    {
        $this->seedSchema();
        FakeWhmcs::$throwForTable = 'tblproductconfiggroups';

        $result = (new ScaffoldOptions(Context::make(new FakeClient())))->handle();

        self::assertStringStartsWith('Scaffold failed:', $result);
    }
}
