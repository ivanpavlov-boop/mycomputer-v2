<?php

return [
    'default' => env('APP_LOCALE', 'bg'),

    'fallback' => env('APP_FALLBACK_LOCALE', 'bg'),

    'supported' => [
        'bg' => [
            'label' => 'Български',
            'native_label' => 'Български',
            'url_prefix' => null,
            'is_default' => true,
        ],
        'en' => [
            'label' => 'English',
            'native_label' => 'English',
            'url_prefix' => 'en',
            'is_default' => false,
        ],
    ],

    'url_strategy' => [
        'default_locale_prefix' => false,
        'secondary_locale_prefixes' => [
            'en' => 'en',
        ],
    ],
];
