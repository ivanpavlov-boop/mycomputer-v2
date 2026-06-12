<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompareRequest;
use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Services\Products\CompareService;
use Illuminate\Http\JsonResponse;

class CompareController extends Controller
{
    public function __construct(private readonly CompareService $compare) {}

    public function __invoke(CompareRequest $request): JsonResponse
    {
        $products = Product::query()
            ->published()
            ->whereIn('id', $request->validated('product_ids'))
            ->with(['brand', 'category', 'images', 'attributes.attribute.group', 'attributes.value'])
            ->get();

        abort_if($products->count() < 2, 422, 'At least two public products are required for comparison.');

        $comparison = $this->compare->buildComparison($products);

        return response()->json([
            'data' => [
                'products' => ProductCardResource::collection($comparison['products'])->resolve(),
                'shared_attributes' => $comparison['shared_attributes'],
                'differences' => $comparison['differences'],
                'prices' => $comparison['prices'],
                'stock_statuses' => $comparison['stock_statuses'],
            ],
        ]);
    }
}
