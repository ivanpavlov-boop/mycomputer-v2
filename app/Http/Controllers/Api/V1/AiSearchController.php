<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductRecommendationResource;
use App\Services\Ai\BuyingGuideService;
use App\Services\Ai\ProductComparisonService;
use App\Services\Ai\ProductRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiSearchController extends Controller
{
    public function __construct(
        private readonly ProductRecommendationService $recommendations,
        private readonly ProductComparisonService $comparison,
        private readonly BuyingGuideService $guides,
    ) {}

    public function search(Request $request): ProductRecommendationResource
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:2000']]);

        return ProductRecommendationResource::make(
            $this->recommendations->recommend($data['query'], Auth::guard('sanctum')->user(), $request->header('X-AI-Session'))
        );
    }

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate(['product_ids' => ['required', 'array', 'min:2'], 'product_ids.*' => ['integer']]);

        return response()->json(['data' => $this->comparison->explain($data['product_ids'])]);
    }

    public function guide(Request $request): JsonResponse
    {
        $data = $request->validate(['topic' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->guides->guide($data['topic'])]);
    }
}
