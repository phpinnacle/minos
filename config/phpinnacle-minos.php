<?php

return [
    'providers' => [
        PHPinnacle\Minos\Payments\Cash::class,
        PHPinnacle\Minos\Payments\Bank::class,
        PHPinnacle\Minos\Payments\Card::class,
        PHPinnacle\Minos\Payments\BePaid::class,
        PHPinnacle\Minos\Payments\Erip::class,
        PHPinnacle\Minos\Payments\Stripe::class,
        PHPinnacle\Minos\Payments\WebPay::class,
    ],
    'queue' => [
        'connection' => 'database',
        'queue' => 'payments',
    ],
    'navigation' => [
        'transaction' => [
            'sort' => 39,
            'icon' => 'phosphor-receipt',
        ],
        'payment_plan' => [
            'sort' => 40,
            'icon' => 'phosphor-wallet',
        ],
        'payment_method' => [
            'sort' => 41,
            'icon' => 'phosphor-credit-card',
        ],
    ],
    'connection' => null,
    'tenancy' => null,
    //    'tenancy' => [
    //        'model' => 'App\\Models\\Tenant',
    //        'default' => 'App\\Models\\Tenant::DEFAULT'
    //    ],
];
