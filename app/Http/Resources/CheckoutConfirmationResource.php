<?php

namespace App\Http\Resources;

use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Payments\PaymentActionPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(CheckoutConfirmationService::class);
        $presentation = app(PaymentActionPresentationService::class)
            ->forCheckoutConfirmation($this->resource);

        return [
            'order_number' => $this->order_number,
            'grand_total' => $this->grand_total,
            'currency' => $presentation['currency'],
            'order_status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => [
                'code' => $this->payment_method,
                'name' => $this->paymentTransactions
                    ->sortByDesc('id')
                    ->first()
                    ?->method
                    ?->name ?: $this->payment_method,
            ],
            'customer_email_masked' => $service->maskEmail($this->customer_email),
            'payment' => [
                'redirect_url' => $presentation['redirect_url'],
                'instructions' => $presentation['instructions'],
                'presentation' => $presentation,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
