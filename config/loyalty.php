<?php

return [
    'points_per_eur' => (int) env('LOYALTY_POINTS_PER_EUR', 1),
    'birthday_bonus_points' => (int) env('LOYALTY_BIRTHDAY_BONUS_POINTS', 100),

    'tiers' => [
        'bronze' => ['threshold' => 0, 'multiplier' => 1.0],
        'silver' => ['threshold' => 1000, 'multiplier' => 1.05],
        'gold' => ['threshold' => 5000, 'multiplier' => 1.1],
        'platinum' => ['threshold' => 10000, 'multiplier' => 1.2],
    ],

    'sources' => [
        'account_creation' => 50,
        'review_approved' => 25,
        'newsletter_signup' => 20,
    ],
];
