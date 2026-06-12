<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteRequestResource;
use App\Services\B2B\QuoteRequestService;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

class CartQuoteController extends Controller
{
    public function __invoke(Request $request, CartService $cartService, QuoteRequestService $quotes): QuoteRequestResource
    {
        $cart = $cartService->resolve($request->header('X-Cart-Session'));

        return new QuoteRequestResource($quotes->createFromCart($request->user(), $cart, $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ])));
    }
}
