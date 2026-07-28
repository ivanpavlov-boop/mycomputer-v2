<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaymentAttemptRequest;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\Order;
use App\Services\Payments\PaymentAttemptService;
use App\Services\Payments\PaymentRetryAuthorizationService;
use Illuminate\Http\JsonResponse;

class AccountPaymentAttemptController extends Controller
{
    public function __invoke(
        PaymentAttemptRequest $request,
        Order $order,
        PaymentRetryAuthorizationService $authorization,
        PaymentAttemptService $attempts,
    ): JsonResponse {
        $result = $attempts->attempt(
            $authorization->accountOwner($order, $request->user()),
            $request->header('Idempotency-Key'),
        );

        return PaymentAttemptResource::make($result)
            ->response()
            ->setStatusCode($result->replayed ? 200 : 201);
    }
}
