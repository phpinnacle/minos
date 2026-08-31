<?php

return [
    'cash' => [
        'label' => 'Cash',
        'description' => 'Cash payment method',
    ],
    'card' => [
        'label' => 'Card',
        'description' => 'Card payment method',
    ],
    'bank' => [
        'label' => 'Bank',
        'description' => 'Bank payment method',
        'fields' => [
            'name' => 'Name',
            'account' => 'Account',
        ],
    ],
    'stripe' => [
        'label' => 'Stripe',
        'description' => 'Stripe payment method',
        'actions' => [
            'test' => 'Test',
        ],
        'fields' => [
            'api_key' => 'API Key',
            'public_key' => 'Public Key',
            'info' => 'Account Info',
        ],
    ],
    'webpay' => [
        'label' => 'WebPay',
        'description' => 'WebPay payment method',
        'fields' => [
            'shop_id' => 'Shop ID',
            'shop_name' => 'Shop Name',
            'return_url' => 'Return URL',
            'cancel_url' => 'Cancel URL',
            'security_mode' => '3DS Mode',
            'secret_key' => 'Secret Key',
            'redirect' => 'Redirect',
            'test_mode' => 'Test Mode',
        ],
        'help' => [
            'return_url' => 'You can use {order} as replacement of real order ID',
            'cancel_url' => 'You can use {order} as replacement of real order ID',
        ],
        'security_mode' => [
            'auto' => 'Auto',
            'force_3ds' => 'Force',
            'force_3ds_only_auth_yes' => 'Force only auth',
            'without_3ds' => 'Without',
        ],
        'methods' => [
            'cardPayment' => 'Card',
            'erip' => 'ERIP',
        ],
    ],
    'bepaid' => [
        'label' => 'BePaid',
        'description' => 'BePaid payment method',
        'fields' => [
            'shop_id' => 'Shop ID',
            'public_key' => 'Public Key',
            'secret_key' => 'Secret Key',
            'test_mode' => 'Test Mode',
            'timeout' => 'Timeout',
            'authorize' => 'Authorize',
        ],
    ],
    'erip' => [
        'label' => 'ERIP',
        'description' => 'ERIP payment method',
        'fields' => [
            'shop_id' => 'Shop ID',
            'secret_key' => 'Secret Key',
            'timeout' => 'Timeout',
            'service' => 'Service',
            'instructions' => 'Instructions',
            'notifications' => 'Notifications',
            'receipt' => 'Receipt',
        ],
    ],
    'paypal' => [
        'label' => 'Paypal',
        'description' => 'Paypal payment method',
    ],
];
