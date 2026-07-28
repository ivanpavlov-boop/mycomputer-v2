<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentRetryUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaymentAttemptRequest;
use App\Http\Resources\PaymentAttemptResource;
use App\Services\Payments\PaymentAttemptService;
use App\Services\Payments\PaymentRetryAuthorizationService;
use App\Services\Payments\PaymentRetryCapabilityService;
use App\Support\Api\ErrorResponse;
use Illuminate\Http\JsonResponse;

class GuestPaymentAttemptController extends Controller
{
    public function __invoke(
        PaymentAttemptRequest $request,
        PaymentRetryAuthorizationService $authorization,
        PaymentRetryCapabilityService $capabilities,
        PaymentAttemptService $attempts,
    ): JsonResponse {
        try {
            $result = $attempts->attempt(
                $authorization->guest($request),
                $request->header('Idempotency-Key'),
            );

            $response = PaymentAttemptResource::make($result)
                ->response()
                ->setStatusCode($result->replayed ? 200 : 201);
        } catch (PaymentRetryUnavailableException $exception) {
            $response = ErrorResponse::make(
                'payment_retry_unavailable',
                $exception->getMessage(),
                404,
            )->withCookie($capabilities->forgetCookie($request));
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
