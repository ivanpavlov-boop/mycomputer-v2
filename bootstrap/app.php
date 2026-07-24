<?php

use App\Exceptions\CartGiftLineImmutableException;
use App\Exceptions\CartMutationConflictException;
use App\Exceptions\CartNotReadyException;
use App\Exceptions\CartPriceChangedException;
use App\Exceptions\CartProductUnavailableException;
use App\Exceptions\CartQuantityUnavailableException;
use App\Http\Middleware\ResolveApiLocale;
use App\Support\Api\ErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', ResolveApiLocale::class);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->expectsJson() && ! str_starts_with($request->path(), 'api/')) {
                return null;
            }

            if ($exception instanceof AuthenticationException) {
                return ErrorResponse::make('unauthenticated', 'Unauthenticated.', 401);
            }

            if ($exception instanceof AuthorizationException) {
                return ErrorResponse::make('forbidden', 'This action is unauthorized.', 403);
            }

            if ($exception instanceof ValidationException) {
                return ErrorResponse::make('validation_error', 'The given data was invalid.', 422, $exception->errors());
            }

            if ($exception instanceof CartPriceChangedException) {
                return ErrorResponse::make('cart_price_changed', $exception->getMessage(), 409);
            }

            if ($exception instanceof CartProductUnavailableException) {
                return ErrorResponse::make(
                    'cart_product_unavailable',
                    $exception->getMessage(),
                    409,
                    $exception->details(),
                );
            }

            if ($exception instanceof CartQuantityUnavailableException) {
                return ErrorResponse::make(
                    'cart_quantity_unavailable',
                    $exception->getMessage(),
                    409,
                    $exception->details(),
                );
            }

            if ($exception instanceof CartNotReadyException) {
                return ErrorResponse::make(
                    'cart_not_ready',
                    $exception->getMessage(),
                    409,
                    $exception->details(),
                );
            }

            if ($exception instanceof CartGiftLineImmutableException) {
                return ErrorResponse::make(
                    'cart_gift_line_immutable',
                    $exception->getMessage(),
                    409,
                );
            }

            if ($exception instanceof CartMutationConflictException) {
                return ErrorResponse::make(
                    'cart_mutation_conflict',
                    $exception->getMessage(),
                    409,
                );
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = $status >= 500 && app()->isProduction() ? 'Server Error' : ($exception->getMessage() ?: 'Server Error');

            return ErrorResponse::make(str($message)->slug('_')->value() ?: 'server_error', $message, $status);
        });
    })->create();
