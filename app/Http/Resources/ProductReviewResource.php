<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => ProductCardResource::make($this->whenLoaded('product')),
            'customer_name' => $this->customer_name,
            'rating' => $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'pros' => $this->pros,
            'cons' => $this->cons,
            'is_verified_purchase' => $this->is_verified_purchase,
            'status' => $this->when($request->routeIs('*.account.*') || str_contains((string) $request->path(), 'account'), $this->status),
            'helpful_votes_count' => $this->whenCounted('helpfulVotes'),
            'not_helpful_votes_count' => $this->whenCounted('notHelpfulVotes'),
            'created_at' => $this->created_at,
        ];
    }
}
