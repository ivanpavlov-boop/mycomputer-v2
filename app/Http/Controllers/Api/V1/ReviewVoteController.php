<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VoteProductReviewRequest;
use App\Http\Resources\ProductReviewVoteResource;
use App\Models\ProductReview;
use App\Services\Reviews\ProductReviewService;
use Illuminate\Support\Facades\Auth;

class ReviewVoteController extends Controller
{
    public function __construct(private readonly ProductReviewService $reviews) {}

    public function __invoke(VoteProductReviewRequest $request, ProductReview $review): ProductReviewVoteResource
    {
        $vote = $this->reviews->vote(
            $review,
            $request->validated('vote_type'),
            Auth::guard('sanctum')->user(),
            $request->header('X-Review-Session'),
        );

        return ProductReviewVoteResource::make($vote);
    }
}
