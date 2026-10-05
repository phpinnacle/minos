<?php

return [
    'transaction' => [
        'singular' => 'Transaction',
        'label' => 'Transactions',
        'group' => 'Sales',
        'empty' => [
            'heading' => 'No transactions',
            'description' => 'Payments and their operations will appear here.',
            'operations' => 'No child operations',
        ],
        'sections' => [
            'operation' => 'Operation',
            'balance' => 'Confirmed funds',
            'details' => 'References and dates',
            'operations' => 'Child operations',
        ],
        'fields' => [
            'id' => 'Transaction ID',
            'number' => 'Number',
            'method' => 'Payment method',
            'type' => 'Operation type',
            'status' => 'Operation status',
            'amount' => 'Amount',
            'description' => 'Description',
            'reason' => 'Refund reason',
            'parent' => 'Parent transaction',
            'source' => 'Source',
            'payer' => 'Payer',
            'external_id' => 'Provider reference',
            'captured' => 'Captured',
            'refunded' => 'Refunded',
            'received' => 'Net received',
            'expires_at' => 'Expires at',
            'processed_at' => 'Processed at',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
        ],
    ],
    'payment_method' => [
        'label' => 'Payment Methods',
        'group' => 'Sales',
        'empty' => [
            'heading' => 'No payment methods',
            'description' => 'Add a payment method to get started.',
        ],
        'actions' => [
            'create' => 'Add Method',
            'delete' => 'Delete',
            'setup' => 'Setup',
            'stripe' => 'Stripe',
            'test' => 'Test',
        ],
        'fields' => [
            'name' => 'Name',
            'provider' => 'Provider',
            'is_active' => 'Active',
            'is_default' => 'Default',
        ],
        'modals' => [
            'create' => [
                'heading' => 'Add Payment Method',
                'description' => 'Configure payment provider to add method',
            ],
        ],
        'pages' => [
            'list' => 'Payment Methods',
            'edit' => 'Edit Method',
        ],
        'sections' => [
            'general' => 'General',
            'options' => 'Options',
        ],
    ],
    'payment_plan' => [
        'label' => 'Payment Plans',
        'group' => 'Sales',
        'empty' => [
            'heading' => 'No payment plans',
            'description' => 'Add a payment plan to get started.',
        ],
        'actions' => [
            'add_part' => 'Add Part',
            'create' => 'Add Plan',
            'delete' => 'Delete',
        ],
        'columns' => [
            'delay' => ':days day|:days days',
        ],
        'fields' => [
            'name' => 'Name',
            'description' => 'Description',
            'parts' => 'Parts',
            'part_value' => 'Value',
            'part_delay' => 'Delay',
            'one_shot' => 'One Time',
            'is_active' => 'Active',
            'is_default' => 'Default',
        ],
        'pages' => [
            'list' => 'Payment Plans',
            'create' => 'Create Plan',
            'edit' => 'Edit Plan',
        ],
        'sections' => [
            'general' => 'General',
            'parts' => 'Parts',
        ],
    ],
];
