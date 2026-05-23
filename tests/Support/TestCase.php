<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeWhmcs::reset();
    }
}
