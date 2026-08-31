<?php

return [
    'cash' => [
        'label' => 'Наличные',
        'description' => 'Метод оплаты наличными',
    ],
    'bank' => [
        'label' => 'Банк',
        'description' => 'Метод оплаты через банк',
        'fields' => [
            'name' => 'Наименование',
            'account' => 'Счет',
        ],
    ],
    'card' => [
        'label' => 'Карта',
        'description' => 'Метод оплаты картой',
    ],
    'stripe' => [
        'label' => 'Stripe',
        'description' => 'Метод оплаты через Stripe',
        'actions' => [
            'test' => 'Проверить',
        ],
        'fields' => [
            'api_key' => 'Ключ API',
            'public_key' => 'Публичный ключ',
            'info' => 'Информация об аккаунте',
        ],
    ],
    'webpay' => [
        'label' => 'WebPay',
        'description' => 'Метод оплаты WebPay',
        'fields' => [
            'shop_id' => 'Идентификатор магазина',
            'shop_name' => 'Наименование магазина',
            'return_url' => 'URL страницы возврата',
            'cancel_url' => 'URL страницы отмены',
            'security_mode' => 'Режим 3DS',
            'secret_key' => 'Секретный ключ',
            'redirect' => 'Перенаправление',
            'test_mode' => 'Тестовый режим',
        ],
        'help' => [
            'return_url' => 'Можно использовать {order.id} для замены на идентификатор заказа',
            'cancel_url' => 'Можно использовать {order.id} для замены на идентификатор заказа',
        ],
        'security_mode' => [
            'auto' => 'Автоматически',
            'force_3ds' => 'Принудительно',
            'force_3ds_only_auth_yes' => 'Принудительно для авторизаций',
            'without_3ds' => 'Отключить',
        ],
        'methods' => [
            'cardPayment' => 'Банковская карта',
            'erip' => 'ERIP',
        ],
    ],
    'bepaid' => [
        'label' => 'BePaid',
        'description' => 'Метод оплаты BePaid',
        'fields' => [
            'shop_id' => 'Идентификатор магазина',
            'public_key' => 'Публичный ключ',
            'secret_key' => 'Секретный ключ',
            'test_mode' => 'Тестовый режим',
            'timeout' => 'Таймаут платежа',
            'authorize' => 'Авторизация',
        ],
    ],
    'erip' => [
        'label' => 'ЕРИП',
        'description' => 'Метод оплаты через ЕРИП',
        'fields' => [
            'shop_id' => 'Идентификатор магазина',
            'secret_key' => 'Секретный ключ',
            'timeout' => 'Таймаут платежа',
            'service' => 'Код услуги ЕРИП',
            'instructions' => 'Инструкции',
            'notifications' => 'Уведомления',
            'receipt' => 'Чек',
        ],
    ],
    'paypal' => [
        'label' => 'Paypal',
        'description' => 'Метод оплаты через Paypal',
    ],
];
