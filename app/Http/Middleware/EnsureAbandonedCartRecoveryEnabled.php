<?php

namespace App\Http\Middleware;

use App\Services\Commerce\PublicCommerceReleaseGate;
use App\Support\Api\CartRecoveryResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbandonedCartRecoveryEnabled
{
    public function __construct(
        private readonly PublicCommerceReleaseGate $releaseGate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('commerce.abandoned_cart_recovery.enabled') !== true
            || ! $this->releaseGate->canStartCheckout()
        ) {
            return CartRecoveryResponse::unavailable();
        }

        return $next($request);
    }
}
