<?php

return [
    'transaction_type' => [
        'payment' => 'Payment',
        'authorize' => 'Authorization',
        'capture' => 'Capture',
        'void' => 'Void',
        'refund' => 'Refund',
    ],
    'transaction_status' => [
        'pending' => 'Pending',
        'success' => 'Succeeded',
        'failure' => 'Failed',
        'cancel' => 'Canceled',
    ],
    'erip_notification' => [
        'sms' => 'SMS',
        'email' => 'E-Mail',
    ],
];
