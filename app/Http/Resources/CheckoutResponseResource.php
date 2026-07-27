<?php

namespace App\Http\Resources;

use App\Services\Orders\CheckoutResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CheckoutResult $result */
        $result = $this->resource;
        $order = $result->order();

        return [
            'accepted' => true,
            'order_number' => $order->order_number,
            'grand_total' => $order->grand_total,
            'currency' => 'EUR',
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'idempotent_replay' => $result->replayed(),
        ];
    }
}
