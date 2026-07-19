<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductBundleResource;
use App\Models\Product;
use App\Services\Bundles\BundleRecommendationService;
use App\Services\Bundles\BundleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BundleController extends Controller
{
    public function index(Request $request, BundleService $bundles): AnonymousResourceCollection
    {
        $query = $bundles->activeQuery()
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')));

        return ProductBundleResource::collection($query->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function show(string $slug, BundleService $bundles): ProductBundleResource
    {
        return ProductBundleResource::make($bundles->findActiveBySlug($slug));
    }

    public function forProduct(string $slug, BundleRecommendationService $recommendations): AnonymousResourceCollection
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return ProductBundleResource::collection($recommendations->forProduct($product));
    }
}
