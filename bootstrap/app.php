<?php

use App\Exceptions\CartGiftLineImmutableException;
use App\Exceptions\CartMutationConflictException;
use App\Exceptions\CartNotReadyException;
use App\Exceptions\CartPriceChangedException;
use App\Exceptions\CartProductUnavailableException;
use App\Exceptions\CartPromotionChangedException;
use App\Exceptions\CartQuantityUnavailableException;
use App\Exceptions\CartRecoveryConsumedException;
use App\Exceptions\CartRecoveryForbiddenException;
use App\Exceptions\CartRecoveryInvalidException;
use App\Exceptions\CartRecoveryRequiresReviewException;
use App\Exceptions\CheckoutAlreadyCompletedException;
use App\Exceptions\CheckoutIdempotencyConflictException;
use App\Exceptions\CheckoutIdempotencyKeyInvalidException;
use App\Exceptions\PaymentAttemptInProgressException;
use App\Exceptions\PaymentIdempotencyConflictException;
use App\Exceptions\PaymentIdempotencyKeyInvalidException;
use App\Exceptions\PaymentMethodUnavailableException;
use App\Exceptions\PaymentProviderDefinitiveFailureException;
use App\Exceptions\PaymentProviderIndeterminateException;
use App\Exceptions\PaymentRetryNotAllowedException;
use App\Exceptions\PaymentRetryUnavailableException;
use App\Http\Middleware\ResolveApiLocale;
use App\Support\Api\CartRecoveryResponse;
use App\Support\Api\ErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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

            if ($exception instanceof CartPromotionChangedException) {
                return ErrorResponse::make('cart_promotion_changed', $exception->getMessage(), 409);
            }

            if (
                $exception instanceof CartRecoveryConsumedException
                || $exception instanceof CartRecoveryInvalidException
                || $exception instanceof CartRecoveryForbiddenException
                || $exception instanceof CartRecoveryRequiresReviewException
            ) {
                return CartRecoveryResponse::unavailable();
            }

            if (
                $request->is('api/v1/cart/recover/*')
                && $exception instanceof HttpExceptionInterface
            ) {
                return CartRecoveryResponse::unavailable();
            }

            if ($exception instanceof CheckoutIdempotencyKeyInvalidException) {
                return ErrorResponse::make(
                    'checkout_idempotency_key_invalid',
                    $exception->getMessage(),
                    422,
                );
            }

            if ($exception instanceof CheckoutIdempotencyConflictException) {
                return ErrorResponse::make(
                    'checkout_idempotency_conflict',
                    $exception->getMessage(),
                    409,
                );
            }

            if ($exception instanceof CheckoutAlreadyCompletedException) {
                return ErrorResponse::make(
                    'checkout_already_completed',
                    $exception->getMessage(),
                    409,
                );
            }

            if ($exception instanceof PaymentMethodUnavailableException) {
                return ErrorResponse::make(
                    'payment_method_unavailable',
                    $exception->getMessage(),
                    422,
                );
            }

            if ($exception instanceof PaymentIdempotencyKeyInvalidException) {
                return ErrorResponse::make(
                    'payment_idempotency_key_invalid',
                    $exception->getMessage(),
                    422,
                );
            }

            if ($exception instanceof PaymentIdempotencyConflictException) {
                return ErrorResponse::make(
                    'payment_idempotency_conflict',
                    $exception->getMessage(),
                    409,
                );
            }

            if ($exception instanceof PaymentAttemptInProgressException) {
                return ErrorResponse::make(
                    'payment_attempt_in_progress',
                    $exception->getMessage(),
                    409,
                )->withHeaders(['Retry-After' => '2']);
            }

            if ($exception instanceof PaymentRetryUnavailableException) {
                return ErrorResponse::make(
                    'payment_retry_unavailable',
                    $exception->getMessage(),
                    404,
                );
            }

            if ($exception instanceof PaymentRetryNotAllowedException) {
                return ErrorResponse::make(
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->statusCode,
                );
            }

            if ($exception instanceof PaymentProviderDefinitiveFailureException) {
                return ErrorResponse::make(
                    'payment_provider_failed',
                    $exception->getMessage(),
                    422,
                );
            }

            if ($exception instanceof PaymentProviderIndeterminateException) {
                return ErrorResponse::make(
                    'payment_result_indeterminate',
                    $exception->getMessage(),
                    503,
                );
            }

            if (
                $exception instanceof MethodNotAllowedHttpException
                && $request->is('api/v1/payments/initiate')
            ) {
                return ErrorResponse::make('not_found', 'Not Found.', 404);
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = $status >= 500 && app()->isProduction() ? 'Server Error' : ($exception->getMessage() ?: 'Server Error');

            return ErrorResponse::make(str($message)->slug('_')->value() ?: 'server_error', $message, $status);
        });
    })->create();
