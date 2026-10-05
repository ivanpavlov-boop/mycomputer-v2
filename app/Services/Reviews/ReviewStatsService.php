<?php

namespace App\Services\Reviews;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ReviewStatsService
{
    public function loadCardSummaries(Collection $products): void
    {
        $products->loadCount([
            'reviews as approved_reviews_count' => fn (Builder $query) => $query->approved(),
        ])->loadAvg([
            'reviews as approved_reviews_avg_rating' => fn (Builder $query) => $query->approved(),
        ], 'rating');
    }

    public function cardSummary(Product $product): array
    {
        $attributes = $product->getAttributes();

        if (array_key_exists('approved_reviews_count', $attributes) && array_key_exists('approved_reviews_avg_rating', $attributes)) {
            $count = $attributes['approved_reviews_count'];
            $average = $attributes['approved_reviews_avg_rating'];
        } else {
            $aggregate = $product->reviews()->approved()
                ->selectRaw('COUNT(*) as aggregate_count, AVG(rating) as aggregate_average')
                ->toBase()
                ->first();
            $count = $aggregate->aggregate_count;
            $average = $aggregate->aggregate_average;
        }

        return [
            'average_rating' => round((float) $average, 2),
            'reviews_count' => (int) $count,
        ];
    }

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
