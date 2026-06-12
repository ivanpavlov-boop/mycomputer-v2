<?php

namespace App\Services\Ai;

use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Products\CompareService;

class ProductComparisonService
{
    public function __construct(
        private readonly CompareService $compare,
        private readonly AiProviderInterface $provider,
    ) {}

    public function explain(array $productIds): array
    {
        $products = Product::query()
            ->published()
            ->whereIn('id', $productIds)
            ->with(['brand', 'category', 'images', 'attributes.attribute.group', 'attributes.value'])
            ->get();

        abort_if($products->count() < 2, 422, 'At least two public products are required.');

        $comparison = $this->compare->buildComparison($products);
        $productPayload = ProductCardResource::collection($products)->resolve();

        return [
            'comparison' => [
                'products' => $productPayload,
                'shared_attributes' => $comparison['shared_attributes'],
                'differences' => $comparison['differences'],
                'prices' => $comparison['prices'],
                'stock_statuses' => $comparison['stock_statuses'],
            ],
            'ai_explanation' => $this->provider->explainComparison($productPayload, $comparison),
        ];
    }
}
