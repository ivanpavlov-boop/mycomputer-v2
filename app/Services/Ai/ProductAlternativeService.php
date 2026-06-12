<?php

namespace App\Services\Ai;

use App\Http\Resources\ProductCardResource;
use App\Models\AiRecommendationLog;
use App\Models\Product;
use App\Models\User;

class ProductAlternativeService
{
    public function alternatives(Product $product, ?User $user = null, ?string $sessionId = null): array
    {
        $base = Product::query()
            ->published()
            ->with(['brand', 'category', 'images'])
            ->whereKeyNot($product->id)
            ->where('category_id', $product->category_id);

        $cheaper = (clone $base)->where('price', '<', $product->price)->orderByDesc('price')->limit(4)->get();
        $better = (clone $base)->where('price', '>=', $product->price)->orderBy('price')->limit(4)->get();
        $similar = (clone $base)->whereBetween('price', [(float) $product->price * 0.85, (float) $product->price * 1.15])->limit(4)->get();

        $result = [
            'product_id' => $product->id,
            'cheaper_alternatives' => ProductCardResource::collection($cheaper)->resolve(),
            'better_alternatives' => ProductCardResource::collection($better)->resolve(),
            'similar_alternatives' => ProductCardResource::collection($similar)->resolve(),
        ];

        AiRecommendationLog::query()->create([
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'query' => $product->name,
            'recommendation_type' => 'alternative_products',
            'results' => $result,
        ]);

        return $result;
    }
}
