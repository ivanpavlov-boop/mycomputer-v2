<?php

return [
    'methods' => [
        'card' => [
            'enabled' => env('PAYMENT_CARD_ENABLED', false),
        ],
        'leasing' => [
            'enabled' => env('PAYMENT_LEASING_ENABLED', false),
            'notification_email' => env('LEASING_NOTIFICATION_EMAIL', 'sales@mycomputer.bg'),
            'allowed_terms_months' => [
                6,
                12,
                18,
                24,
                36,
                48,
            ],
            'contact_methods' => [
                'phone',
                'email',
                'either',
            ],
            'contact_time_slots' => [
                'anytime',
                'morning',
                'afternoon',
                'evening',
            ],
            'consent_version' => 'manual-leasing-v1',
            'customer_note_max_length' => 1000,
            'internal_note_max_length' => 2000,
        ],
    ],
];
