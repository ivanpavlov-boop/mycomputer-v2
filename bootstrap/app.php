<?php

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
        //
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

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = $status >= 500 && app()->isProduction() ? 'Server Error' : ($exception->getMessage() ?: 'Server Error');

            return ErrorResponse::make(str($message)->slug('_')->value() ?: 'server_error', $message, $status);
        });
    })->create();
