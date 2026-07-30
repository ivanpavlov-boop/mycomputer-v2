<?php

return [
    'approved' => env('LEGAL_CONTENT_APPROVED', false),

    'locale' => 'bg',

    'operator' => [
        'legal_name' => '„Тандем компютърс“ ЕООД',
        'eik' => '202410637',
        'address' => 'гр. Перник, ул. „Г. С. Раковски“ №3/6А',
        'email' => 'sales@mycomputer.bg',
        'brands' => [
            'MyComputer.bg',
            'COMPUTER2U',
        ],
    ],

    'manifest_path' => base_path(
        'frontend/app/data/legal/legal-content-manifest.json',
    ),

    'approval_record_path' => base_path(
        'docs/legal/LEGAL_CONTENT_APPROVAL_2026-07-30.json',
    ),

    'source_pages' => [
        'terms' => base_path('frontend/app/data/legal/terms.bg.ts'),
        'privacy' => base_path('frontend/app/data/legal/privacy.bg.ts'),
    ],
];
