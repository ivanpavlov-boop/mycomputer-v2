<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method' => PaymentMethodResource::make($this->whenLoaded('method')),
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'redirect_url' => $this->raw_response['redirect_url'] ?? null,
            'instructions' => $this->raw_response['instructions'] ?? null,
            'paid_at' => $this->paid_at,
            'failed_at' => $this->failed_at,
        ];
    }
}
