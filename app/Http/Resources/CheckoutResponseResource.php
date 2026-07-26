<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accepted' => true,
            'order_number' => $this->order_number,
            'grand_total' => $this->grand_total,
            'currency' => 'EUR',
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
        ];
    }
}
