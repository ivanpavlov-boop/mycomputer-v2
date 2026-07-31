<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\CheckoutResponseResource;
use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Orders\IdempotentCheckoutService;
use App\Services\Payments\PaymentRetryCapabilityService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly IdempotentCheckoutService $checkoutService,
        private readonly CheckoutConfirmationService $checkoutConfirmations,
        private readonly PaymentRetryCapabilityService $paymentRetryCapabilities,
    ) {}

    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkoutService->checkout($request, $request->checkoutData());

        $response = CheckoutResponseResource::make($result)
            ->response()
            ->setStatusCode(201)
            ->withCookie(
                $this->checkoutConfirmations->cookie(
                    $result->confirmationCapability(),
                    $request,
                ),
            );

        if ($result->paymentRetryCapability() !== null) {
            $response->withCookie(
                $this->paymentRetryCapabilities->cookie(
                    $result->paymentRetryCapability(),
                    $request,
                ),
            );
        }

        return $response;
    }
}
