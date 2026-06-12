<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Ai\ProductAlternativeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductAlternativeController extends Controller
{
    public function __construct(private readonly ProductAlternativeService $alternatives) {}

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => $this->alternatives->alternatives($product, Auth::guard('sanctum')->user(), $request->header('X-AI-Session')),
        ]);
    }
}
