<?php

namespace App\Services\Orders;

use App\Exceptions\PaymentMethodUnavailableException;
use App\Models\CheckoutIdempotencyRecord;
use App\Services\Cart\CartContextResolver;
use App\Services\Payments\PaymentMethodAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdempotentCheckoutService
{
    public function __construct(
        private readonly CartContextResolver $cartContext,
        private readonly CheckoutIdempotencyService $idempotency,
        private readonly CheckoutService $checkout,
        private readonly CheckoutConfirmationService $checkoutConfirmations,
        private readonly PaymentMethodAvailabilityService $paymentMethods,
    ) {}

    public function checkout(Request $request, array $validatedPayload): CheckoutResult
    {
        try {
            $this->paymentMethods->requireAvailable($validatedPayload['payment_method']);
        } catch (PaymentMethodUnavailableException $exception) {
            $this->cartContext->assertSessionOwnership($request);

            throw $exception;
        }

        $submittedCartSession = $this->cartContext->sessionId($request);
        $context = $this->idempotency->context(
            $request->header('Idempotency-Key'),
            $validatedPayload,
            $submittedCartSession,
            $request->user('sanctum')?->getKey(),
        );
        $completed = $this->idempotency->findCompletedReplay($context);

        if ($completed) {
            return $this->replay($completed);
        }

        $cart = $this->cartContext->resolve($request);
        $completed = $this->idempotency->findCompletedReplay($context);

        if ($completed) {
            return $this->replay($completed);
        }

        return $this->checkout->checkout(
            $cart,
            $validatedPayload,
            $context,
        );
    }

    private function replay(CheckoutIdempotencyRecord $completed): CheckoutResult
    {
        return DB::transaction(function () use ($completed): CheckoutResult {
            $order = $completed->order()->with('paymentTransactions.method')->firstOrFail();

            return new CheckoutResult(
                $order,
                $this->checkoutConfirmations->issue($order),
                replayed: true,
            );
        });
    }
}
