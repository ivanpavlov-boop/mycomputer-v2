<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

final class CartRecoveryResponse
{
    public const ERROR_CODE = 'cart_recovery_unavailable';

    public const MESSAGE = 'Recovery link is unavailable.';

    public static function unavailable(): JsonResponse
    {
        return self::privateResponse(
            ErrorResponse::make(self::ERROR_CODE, self::MESSAGE, 404),
        );
    }

    public static function privateResponse(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
