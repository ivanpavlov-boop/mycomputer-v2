<?php

return [
    'public' => [
        'enabled' => env('PUBLIC_COMMERCE_ENABLED', false),
        'confirmation_enabled' => env('PUBLIC_COMMERCE_CONFIRMATION_ENABLED', false),
    ],

    'abandoned_cart_recovery' => [
        'enabled' => env('ABANDONED_CART_RECOVERY_ENABLED', false),
    ],
];
