<?php

return [
    'provider' => env('EMAIL_MARKETING_PROVIDER', 'log'),

    'abandoned_cart' => [
        'threshold_minutes' => (int) env('ABANDONED_CART_THRESHOLD_MINUTES', 60),
        'recovery_token_days' => (int) env('ABANDONED_CART_RECOVERY_TOKEN_DAYS', 14),
        'expire_after_days' => (int) env('ABANDONED_CART_EXPIRE_AFTER_DAYS', 14),
        'frontend_recovery_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:3000')).'/cart/recover/{token}',
        'support_contact' => env('SUPPORT_CONTACT_EMAIL', 'support@mycomputer.bg'),
        'sequence' => [
            1 => ['template' => 'abandoned_cart_1', 'delay_hours' => 1],
            2 => ['template' => 'abandoned_cart_2', 'delay_hours' => 24],
            3 => ['template' => 'abandoned_cart_3', 'delay_hours' => 72],
        ],
    ],

    'templates' => [
        'welcome' => ['subject' => 'Добре дошли в mycomputer.bg', 'view' => 'emails.marketing.welcome'],
        'abandoned_cart_1' => ['subject' => 'Забравихте продукти в количката', 'view' => 'emails.marketing.abandoned-cart-1'],
        'abandoned_cart_2' => ['subject' => 'Вашата количка ви очаква', 'view' => 'emails.marketing.abandoned-cart-2'],
        'abandoned_cart_3' => ['subject' => 'Последно напомняне за количката', 'view' => 'emails.marketing.abandoned-cart-3'],
        'order_created' => ['subject' => 'Поръчката е получена', 'view' => 'emails.marketing.order-created'],
        'order_paid' => ['subject' => 'Плащането е потвърдено', 'view' => 'emails.marketing.order-paid'],
        'order_shipped' => ['subject' => 'Поръчката е изпратена', 'view' => 'emails.marketing.order-shipped'],
        'order_delivered' => ['subject' => 'Поръчката е доставена', 'view' => 'emails.marketing.order-delivered'],
        'order_cancelled' => ['subject' => 'Поръчката е отказана', 'view' => 'emails.marketing.order-cancelled'],
        'review_request' => ['subject' => 'Споделете мнение за покупката', 'view' => 'emails.marketing.review-request'],
        'wishlist_reminder' => ['subject' => 'Продуктите в списъка ви чакат', 'view' => 'emails.marketing.wishlist-reminder'],
        'price_drop' => ['subject' => 'Цената падна', 'view' => 'emails.marketing.price-drop'],
        'back_in_stock' => ['subject' => 'Продуктът отново е наличен', 'view' => 'emails.marketing.back-in-stock'],
    ],
];
