<?php

declare(strict_types=1);

namespace Silo\WhmcsModule\Tests\Unit;

use Silo\WhmcsModule\Tests\Support\FakeWhmcs;
use Silo\WhmcsModule\Tests\Support\TestCase;
use Silo\WhmcsModule\Whmcs\CustomFieldStore;

final class CustomFieldStoreTest extends TestCase
{
    public function testReadHandlesSingleCustomFieldObject(): void
    {
        FakeWhmcs::$localApiHandler = static function (string $action): ?array {
            if ($action !== 'GetClientsProducts') {
                return null;
            }
            return [
                'result' => 'success',
                'products' => ['product' => [[
                    'customfields' => ['customfield' => [
                        'id' => 1,
                        'name' => 'silo_user_id',
                        'value' => '42',
                    ]],
                ]]],
            ];
        };

        self::assertSame('42', (new CustomFieldStore())->read(7, 'silo_user_id'));
    }
}
