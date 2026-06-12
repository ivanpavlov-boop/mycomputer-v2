<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'vat_number' => $this->vat_number,
            'phone' => $this->phone,
            'country' => $this->country,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'is_default' => $this->is_default,
        ];
    }
}
