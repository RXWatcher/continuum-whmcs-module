<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;
use Continuum\WhmcsModule\Whmcs\ConfigOptionScaffolder;

final class ConfigOptionScaffolderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeWhmcs::seedTable('tblcurrencies', [['id' => 1]]);
        FakeWhmcs::seedTable('tblproducts', [['id' => 10, 'servertype' => 'continuum']]);
    }

    public function testFreshScaffoldCreatesGroupOptionsPricingAndLinks(): void
    {
        $r = (new ConfigOptionScaffolder())->scaffold([]);

        self::assertSame('Continuum Options', $r['group']);
        self::assertContains("group 'Continuum Options'", $r['created']);
        self::assertContains('link -> product 10', $r['created']);

        self::assertCount(1, FakeWhmcs::rows('tblproductconfiggroups'));
        // The 8 base option specs (no libraries supplied).
        self::assertCount(8, FakeWhmcs::rows('tblproductconfigoptions'));
        self::assertNotEmpty(FakeWhmcs::rows('tblpricing'), '0.00 pricing rows created');
        self::assertCount(1, FakeWhmcs::rows('tblproductconfiglinks'));

        $link = FakeWhmcs::rows('tblproductconfiglinks')[0];
        self::assertSame(10, $link['pid']);
    }

    public function testRerunIsIdempotent(): void
    {
        $scaffolder = new ConfigOptionScaffolder();
        $scaffolder->scaffold([]);
        $second = $scaffolder->scaffold([]);

        self::assertContains("group 'Continuum Options' (exists)", $second['skipped']);
        self::assertContains('link -> product 10 (exists)', $second['skipped']);
        self::assertNotContains("group 'Continuum Options'", $second['created']);

        // No duplication.
        self::assertCount(1, FakeWhmcs::rows('tblproductconfiggroups'));
        self::assertCount(8, FakeWhmcs::rows('tblproductconfigoptions'));
        self::assertCount(1, FakeWhmcs::rows('tblproductconfiglinks'));
    }

    public function testPerLibraryOptionsUseAttributeMapperRecognisableNames(): void
    {
        $r = (new ConfigOptionScaffolder())->scaffold([
            ['id' => 3, 'name' => 'Documentaries'],
        ]);

        $names = array_column(FakeWhmcs::rows('tblproductconfigoptions'), 'optionname');
        self::assertCount(9, $names); // 8 base + 1 library
        // Name must be exactly "Library 3" — AttributeMapper matches
        // /^library (id )?N$/, the human name must not leak in.
        self::assertContains('Library 3', $names);
        self::assertStringContainsString('Library 3', implode('|', $r['created']));
    }
}
