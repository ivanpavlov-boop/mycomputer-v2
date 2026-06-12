<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductReviewRequest;
use App\Http\Resources\ProductReviewResource;
use App\Http\Resources\ProductReviewSummaryResource;
use App\Models\Product;
use App\Services\Reviews\ProductReviewService;
use App\Services\Reviews\ReviewStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductReviewController extends Controller
{
    public function __construct(
        private readonly ProductReviewService $reviews,
        private readonly ReviewStatsService $stats,
    ) {}

    public function index(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);
        $query = $product->reviews()
            ->approved()
            ->withCount(['helpfulVotes', 'notHelpfulVotes']);

        if ($request->filled('rating')) {
            $query->where('rating', $request->integer('rating'));
        }

        match ($request->query('sort', 'newest')) {
            'oldest' => $query->oldest(),
            'highest_rating' => $query->orderByDesc('rating')->latest(),
            'lowest_rating' => $query->orderBy('rating')->latest(),
            'most_helpful' => $query->orderByDesc('helpful_votes_count')->latest(),
            default => $query->latest(),
        };

        $reviews = $query->paginate($perPage);

        return response()->json([
            'data' => ProductReviewResource::collection($reviews->items())->resolve(),
            'summary' => ProductReviewSummaryResource::make($this->stats->summary($product))->resolve(),
            'links' => [
                'first' => $reviews->url(1),
                'last' => $reviews->url($reviews->lastPage()),
                'prev' => $reviews->previousPageUrl(),
                'next' => $reviews->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    public function store(StoreProductReviewRequest $request, string $slug): ProductReviewResource
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();
        $review = $this->reviews->submit($product, $request->validated(), Auth::guard('sanctum')->user());

        return ProductReviewResource::make($review);
    }
}
