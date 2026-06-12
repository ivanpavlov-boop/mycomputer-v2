<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

class SuccessResponse
{
    public static function make(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'data' => $data,
            'meta' => $meta ?: null,
        ], fn ($value): bool => $value !== null), $status);
    }
}
