<?php

return [
    'transaction_type' => [
        'payment' => 'Платёж',
        'authorize' => 'Авторизация',
        'capture' => 'Списание',
        'void' => 'Отмена авторизации',
        'refund' => 'Возврат',
    ],
    'transaction_status' => [
        'pending' => 'Ожидание',
        'success' => 'Успешно',
        'failure' => 'Ошибка',
        'cancel' => 'Отменено',
    ],
    'erip_notification' => [
        'sms' => 'SMS',
        'email' => 'Электронная почта',
    ],
];
