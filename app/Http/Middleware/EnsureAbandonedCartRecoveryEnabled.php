<?php

namespace App\Http\Middleware;

use App\Support\Api\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbandonedCartRecoveryEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('commerce.abandoned_cart_recovery.enabled') !== true) {
            return ErrorResponse::make('not_found', 'Not Found.', 404)
                ->withHeaders([
                    'Cache-Control' => 'no-store, private',
                ]);
        }

        return $next($request);
    }
}
