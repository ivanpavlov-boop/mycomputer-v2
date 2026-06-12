<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

class ErrorResponse
{
    public static function make(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ],
        ], fn ($value): bool => $value !== null), $status);
    }
}
