<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'query' => $this->resource['query'] ?? null,
            'intent' => $this->resource['intent'] ?? [],
            'summary' => $this->resource['summary'] ?? null,
            'reasoning' => $this->resource['reasoning'] ?? [],
            'products' => $this->resource['products'] ?? [],
        ];
    }
}
