<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingOfficeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => ShippingProviderResource::make($this->whenLoaded('provider')),
            'office_id' => $this->office_id,
            'name' => $this->name,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'address' => $this->address,
            'phone' => $this->phone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
