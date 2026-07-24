<?php

namespace App\Services\Cart;

use App\Models\Cart;

final readonly class CartPricingRefreshResult
{
    public function __construct(
        public Cart $cart,
        public bool $changed,
        public bool $requiresReview,
        public array $regularItemChanges,
        public array $bundleChanges,
        public bool $giftStateChanged,
        public float $subtotalBefore,
        public float $subtotalAfter,
    ) {}
}
