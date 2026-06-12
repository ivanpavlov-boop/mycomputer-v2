<?php

use App\Models\Product;

return [
    'driver' => env('SCOUT_DRIVER', 'database'),

    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Product::class => [
                'filterableAttributes' => [
                    'active',
                    'attributes',
                    'bestseller',
                    'brand',
                    'brand_slug',
                    'category',
                    'category_path',
                    'category_slug',
                    'featured',
                    'new_product',
                    'price',
                    'stock_status',
                ],
                'sortableAttributes' => [
                    'bestseller',
                    'featured',
                    'name',
                    'price',
                    'published_at',
                ],
                'searchableAttributes' => [
                    'name',
                    'slug',
                    'sku',
                    'ean',
                    'mpn',
                    'brand',
                    'category',
                    'category_path',
                    'short_description',
                    'description',
                    'attributes',
                    'searchable_keywords',
                ],
                'typoTolerance' => [
                    'enabled' => true,
                    'minWordSizeForTypos' => [
                        'oneTypo' => 4,
                        'twoTypos' => 8,
                    ],
                ],
            ],
        ],
    ],
];
