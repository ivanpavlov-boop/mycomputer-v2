<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CheckoutConfirmationUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutConfirmationResource;
use App\Services\Orders\CheckoutConfirmationService;
use App\Support\Api\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutConfirmationController extends Controller
{
    public function __invoke(
        Request $request,
        CheckoutConfirmationService $confirmations,
    ): JsonResponse {
        try {
            $capability = $confirmations->resolve(
                $request->cookie(CheckoutConfirmationService::COOKIE_NAME),
            );
        } catch (CheckoutConfirmationUnavailableException) {
            return $this->unavailable($request, $confirmations);
        }

        return CheckoutConfirmationResource::make($capability->order)
            ->response()
            ->withHeaders($this->noStoreHeaders());
    }

    private function unavailable(
        Request $request,
        CheckoutConfirmationService $confirmations,
    ): JsonResponse {
        return ErrorResponse::make(
            'checkout_confirmation_unavailable',
            'Checkout confirmation is unavailable.',
            404,
        )
            ->withHeaders($this->noStoreHeaders())
            ->withCookie($confirmations->forgetCookie($request));
    }

    /**
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }
}
