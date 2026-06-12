<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\BrandResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductBundleResource;
use App\Http\Resources\ProductCardResource;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(ProductIndexRequest $request, SearchServiceInterface $search): JsonResponse
    {
        $results = $search->search($request->validated());
        $products = ProductCardResource::collection($results['products']);
        $bundles = ProductBundleResource::collection($results['bundles']);

        return response()->json([
            'data' => [
                'products' => $products->response()->getData(true),
                'bundles' => $bundles->response()->getData(true),
                'total' => $results['products']->total(),
                'bundle_total' => $results['bundles']->total(),
                'page' => $results['products']->currentPage(),
                'per_page' => $results['products']->perPage(),
                'categories' => CategoryResource::collection($results['categories']),
                'brands' => BrandResource::collection($results['brands']),
                'suggestions' => $results['suggestions'],
                'available_filters' => $results['available_filters'],
                'filters' => [
                    'meilisearch_ready' => $results['meilisearch_ready'],
                    'engine' => $results['engine'],
                ],
            ],
        ]);
    }

    public function suggestions(Request $request, SearchServiceInterface $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => [
                'suggestions' => $search->suggestions((string) ($validated['q'] ?? '')),
            ],
        ]);
    }
}
