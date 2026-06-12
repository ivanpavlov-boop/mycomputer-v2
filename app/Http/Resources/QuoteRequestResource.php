<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_number' => $this->quote_number,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'company_name' => $this->company_name,
            'vat_number' => $this->vat_number,
            'status' => $this->status,
            'source' => $this->source,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'valid_until' => $this->valid_until,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at,
            'converted_order_id' => $this->converted_order_id,
            'company' => new B2BCompanyResource($this->whenLoaded('company')),
            'items' => QuoteRequestItemResource::collection($this->whenLoaded('items')),
            'messages' => QuoteRequestMessageResource::collection($this->whenLoaded('messages')),
            'files' => QuoteRequestFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at,
        ];
    }
}
