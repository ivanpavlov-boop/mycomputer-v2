<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PrivatePaymentAttemptResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $response = app(ExceptionHandler::class)->render($request, $exception);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
