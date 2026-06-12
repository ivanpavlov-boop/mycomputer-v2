<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'average_rating' => $this->resource['average_rating'],
            'total_reviews' => $this->resource['total_reviews'],
            'reviews_count' => $this->resource['reviews_count'],
            'verified_reviews_count' => $this->resource['verified_reviews_count'],
            'rating_distribution' => $this->resource['rating_distribution'],
        ];
    }
}
