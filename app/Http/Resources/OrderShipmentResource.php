<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider?->only(['id', 'name', 'code']),
            'method' => $this->method?->only(['id', 'name', 'code', 'type']),
            'tracking_number' => $this->tracking_number,
            'office' => $this->office?->only(['id', 'name', 'city', 'address']),
            'delivery_type' => $this->delivery_type,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'address' => $this->address,
            'price' => $this->price,
            'status' => $this->status,
        ];
    }
}
