<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportProductReviewRequest;
use App\Http\Resources\ProductReviewReportResource;
use App\Models\ProductReview;
use App\Services\Reviews\ProductReviewService;
use Illuminate\Support\Facades\Auth;

class ReviewReportController extends Controller
{
    public function __construct(private readonly ProductReviewService $reviews) {}

    public function __invoke(ReportProductReviewRequest $request, ProductReview $review): ProductReviewReportResource
    {
        $report = $this->reviews->report(
            $review,
            $request->validated(),
            Auth::guard('sanctum')->user(),
            $request->header('X-Review-Session'),
        );

        return ProductReviewReportResource::make($report);
    }
}
