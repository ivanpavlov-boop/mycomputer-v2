<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductQuoteRequest;
use App\Http\Resources\QuoteRequestResource;
use App\Models\Product;
use App\Services\B2B\QuoteRequestService;

class ProductQuoteController extends Controller
{
    public function __invoke(string $slug, ProductQuoteRequest $request, QuoteRequestService $quotes): QuoteRequestResource
    {
        $product = Product::query()->where('slug', $slug)->firstOrFail();

        return new QuoteRequestResource($quotes->createFromProduct($request->user(), $product, $request->validated()));
    }
}
