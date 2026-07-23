<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteRequestResource;
use App\Services\B2B\QuoteRequestService;
use App\Services\Cart\CartContextResolver;
use Illuminate\Http\Request;

class CartQuoteController extends Controller
{
    public function __invoke(Request $request, CartContextResolver $cartContext, QuoteRequestService $quotes): QuoteRequestResource
    {
        $cart = $cartContext->resolve($request);

        return new QuoteRequestResource($quotes->createFromCart($request->user(), $cart, $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ])));
    }
}
