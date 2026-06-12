<?php

namespace App\Services\Reviews;

use App\Models\Product;

class ReviewStatsService
{
    public function summary(Product $product): array
    {
        $query = $product->reviews()->approved();
        $total = (clone $query)->count();
        $average = $total > 0 ? round((float) (clone $query)->avg('rating'), 2) : 0.0;
        $distribution = collect(range(1, 5))
            ->mapWithKeys(fn (int $rating): array => [$rating => (clone $query)->where('rating', $rating)->count()])
            ->all();

        return [
            'average_rating' => $average,
            'total_reviews' => $total,
            'reviews_count' => $total,
            'verified_reviews_count' => (clone $query)->where('is_verified_purchase', true)->count(),
            'rating_distribution' => $distribution,
        ];
    }
}
