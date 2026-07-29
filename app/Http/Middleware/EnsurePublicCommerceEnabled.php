<?php

namespace App\Http\Middleware;

use App\Services\Commerce\PublicCommerceReleaseGate;
use App\Support\Api\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicCommerceEnabled
{
    public function __construct(
        private readonly PublicCommerceReleaseGate $releaseGate,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (! $this->releaseGate->canStartCheckout()) {
            return ErrorResponse::make('not_found', 'Not Found.', 404)
                ->withHeaders([
                    'Cache-Control' => 'no-store, private',
                ]);
        }

        return $next($request);
    }
}
