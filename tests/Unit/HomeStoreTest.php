<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Unit;

use Continuum\WhmcsModule\HomeStore;
use Continuum\WhmcsModule\Tests\Support\FakeWhmcs;
use Continuum\WhmcsModule\Tests\Support\TestCase;

final class HomeStoreTest extends TestCase
{
    public function testGetReturnsNullWhenAbsent(): void
    {
        self::assertNull((new HomeStore())->get('jane@example.com'));
    }

    public function testPutThenGetRoundTrips(): void
    {
        $store = new HomeStore();
        $store->put('Jane@Example.com', 2, 77);

        self::assertSame(
            ['serverid' => 2, 'userid' => 77],
            $store->get('jane@example.com'),
            'email is normalised on both sides'
        );
    }

    public function testPutUpdatesExistingPointerWithoutDuplicating(): void
    {
        $store = new HomeStore();
        $store->put('jane@example.com', 2, 77);
        $store->put('jane@example.com', 5, 88);

        self::assertSame(['serverid' => 5, 'userid' => 88], $store->get('jane@example.com'));
        self::assertCount(1, FakeWhmcs::rows('mod_continuum_home'));
    }

    public function testInvalidInputsAreIgnored(): void
    {
        $store = new HomeStore();
        $store->put('', 2, 77);
        $store->put('jane@example.com', 0, 77);
        $store->put('jane@example.com', 2, 0);

        self::assertSame([], FakeWhmcs::rows('mod_continuum_home'));
        self::assertNull($store->get(''));
    }

    public function testForgetRemovesPointer(): void
    {
        $store = new HomeStore();
        $store->put('jane@example.com', 2, 77);
        self::assertNotNull($store->get('jane@example.com'));

        $store->forget('JANE@example.com');

        self::assertNull($store->get('jane@example.com'));
    }

    public function testForgetIsSafeOnAbsentPointer(): void
    {
        (new HomeStore())->forget('nobody@example.com');
        self::assertSame([], FakeWhmcs::rows('mod_continuum_home'));
    }

    public function testRenameMovesPointerToNewEmail(): void
    {
        $store = new HomeStore();
        $store->put('old@example.com', 2, 77);

        $store->rename('OLD@example.com', 'new@example.com');

        self::assertSame(['serverid' => 2, 'userid' => 77], $store->get('new@example.com'));
        self::assertNull($store->get('old@example.com'));
    }

    public function testRenameOnAbsentPointerIsNoop(): void
    {
        (new HomeStore())->rename('old@example.com', 'new@example.com');
        self::assertSame([], FakeWhmcs::rows('mod_continuum_home'));
    }

    public function testRenameWithSameEmailIsNoop(): void
    {
        $store = new HomeStore();
        $store->put('jane@example.com', 2, 77);
        $store->rename('jane@example.com', 'JANE@example.com');

        self::assertCount(1, FakeWhmcs::rows('mod_continuum_home'));
    }
}
