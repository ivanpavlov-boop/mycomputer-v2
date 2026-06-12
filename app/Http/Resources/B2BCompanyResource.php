<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class B2BCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'vat_number' => $this->vat_number,
            'company_number' => $this->company_number,
            'mol' => $this->mol,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'payment_terms' => $this->payment_terms,
            'users' => B2BCompanyUserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at,
        ];
    }
}
