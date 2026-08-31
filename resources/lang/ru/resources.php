<?php

return [
    'payment_method' => [
        'label' => 'Способы оплаты',
        'group' => 'Продажи',
        'empty' => [
            'heading' => 'Нет способов оплаты',
            'description' => 'Добавьте способ оплаты, чтобы начать.',
        ],
        'actions' => [
            'create' => 'Добавить способ',
            'delete' => 'Удалить',
            'setup' => 'Настроить',
            'stripe' => 'Stripe',
            'test' => 'Тестировать',
        ],
        'fields' => [
            'name' => 'Название',
            'provider' => 'Поставщик',
            'is_active' => 'Активен',
            'is_default' => 'По умолчанию',
        ],
        'modals' => [
            'create' => [
                'heading' => 'Добавить способ оплаты',
                'description' => 'Настройте поставщика оплаты для добавления способа',
            ],
        ],
        'pages' => [
            'list' => 'Способы оплаты',
            'edit' => 'Редактировать способ',
        ],
        'sections' => [
            'general' => 'Общее',
            'options' => 'Опции',
        ],
    ],
    'payment_plan' => [
        'label' => 'Платежные планы',
        'group' => 'Продажи',
        'empty' => [
            'heading' => 'Нет платежных планов',
            'description' => 'Добавьте платежный план, чтобы начать.',
        ],
        'actions' => [
            'add_part' => 'Добавить часть',
            'create' => 'Добавить план',
            'delete' => 'Удалить',
        ],
        'columns' => [
            'delay' => ':days день|:days дней',
        ],
        'fields' => [
            'name' => 'Название',
            'description' => 'Описание',
            'parts' => 'Части',
            'part_value' => 'Значение',
            'part_delay' => 'Задержка',
            'one_shot' => 'Единовременный',
            'is_active' => 'Активен',
            'is_default' => 'По умолчанию',
        ],
        'pages' => [
            'list' => 'Платежные планы',
            'create' => 'Создать план',
            'edit' => 'Редактировать план',
        ],
        'sections' => [
            'general' => 'Общее',
            'parts' => 'Части',
        ],
    ],
];
