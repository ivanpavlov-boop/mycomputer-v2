<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductReviewResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountReviewController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return ProductReviewResource::collection(
            $request->user()
                ->productReviews()
                ->with(['product.brand', 'product.category', 'product.images'])
                ->latest()
                ->paginate(20)
        );
    }
}
