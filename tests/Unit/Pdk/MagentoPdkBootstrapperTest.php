<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\tests\Unit\Pdk;

use Mock\MockMagentoUser;
use MyParcelNL\Magento\tests\Uses\UsesMockMagentoPdkInstance;
use MyParcelNL\Pdk\Facade\Pdk;
use function MyParcelNL\Pdk\Tests\usesShared;

usesShared(new UsesMockMagentoPdkInstance());

it('returns proper permission callback', function (array $roles, bool $expected) {
    foreach ($roles as $role) {
        MockMagentoUser::addRole($role);
    }

    $actual = (Pdk::get('routeBackendPermissionCallback'))();

    expect($actual)->toBe($expected);
})->with([
    'no roles'                    => [
        'roles'    => [],
        'expected' => false,
    ],
    'no administrator'            => [
        'roles'    => ['subscriber'],
        'expected' => false,
    ],
    'nobody, subscriber'          => [
        'roles'    => ['nobody', 'subscriber'],
        'expected' => false,
    ],
    'administrator'               => [
        'roles'    => ['administrator'],
        'expected' => true,
    ],
    'shop manager'                => [
        'roles'    => ['shop_manager'],
        'expected' => true,
    ],
    'subscriber, shop manager'    => [
        'roles'    => ['subscriber', 'shop_manager'],
        'expected' => true,
    ],
    'subscriber, administrator'   => [
        'roles'    => ['subscriber', 'administrator'],
        'expected' => true,
    ],
    'shop manager, administrator' => [
        'roles'    => ['shop_manager', 'administrator'],
        'expected' => true,
    ],
    'shop manager, subscriber'    => [
        'roles'    => ['shop_manager', 'subscriber'],
        'expected' => true,
    ],
    'many, shop_manager'          => [
        'roles'    => ['nobody', 'subscriber', 'anybody', 'shop_manager'],
        'expected' => true,
    ],
]);
