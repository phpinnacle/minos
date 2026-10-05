<?php

return [
    'payment_parts' => [
        'format' => 'The payment parts must be an array.',
        'empty' => 'The payment parts must not be empty.',
        'invalid' => 'Each payment part must have an integer percentage from 1 to 100 and a delay from 0 to 365 days.',
        'sum' => 'The sum of payment parts must be 100%.',
    ],
    'payment_scheme' => [
        'invalid' => 'The payment scheme is invalid.',
        'format' => 'The payment parts must be an array.',
        'empty' => 'The payment parts must not be empty.',
        'date' => 'The payment part date must be in the future.',
        'amount' => [
            'eq' => 'The payment part amount must be equal to :value.',
            'neq' => 'The payment part amount must not be equal to :value.',
            'lt' => 'The payment part amount must be less than :value.',
            'lte' => 'The payment part amount must be less than or equal to :value.',
            'gt' => 'The payment part amount must be greater than :value.',
            'gte' => 'The payment part amount must be greater than or equal to :value.',
        ],
    ],
];
