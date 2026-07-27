<?php

namespace App\Http\Resources;

use App\Services\Orders\CheckoutConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(CheckoutConfirmationService::class);
        $payment = $service->safePaymentPresentation($this->resource);

        return [
            'order_number' => $this->order_number,
            'grand_total' => $this->grand_total,
            'currency' => $payment['currency'],
            'order_status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => [
                'code' => $this->payment_method,
                'name' => $payment['method_name'],
            ],
            'customer_email_masked' => $service->maskEmail($this->customer_email),
            'payment' => [
                'redirect_url' => $payment['redirect_url'],
                'instructions' => $payment['instructions'],
            ],
            'created_at' => $this->created_at,
        ];
    }
}
