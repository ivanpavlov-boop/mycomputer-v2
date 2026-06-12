<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'b2b_company_id' => $this->b2b_company_id,
            'quote_request_id' => $this->quote_request_id,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'customer_name' => $this->customer_name,
            'subtotal' => $this->subtotal,
            'shipping_price' => $this->shipping_price,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'shipping_method' => $this->shipping_method,
            'shipping_status' => $this->shipping_status,
            'status' => $this->status,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'bundle_items' => OrderBundleItemResource::collection($this->whenLoaded('bundleItems')),
            'shipments' => OrderShipmentResource::collection($this->whenLoaded('shipments')),
            'payment_transactions' => PaymentTransactionResource::collection($this->whenLoaded('paymentTransactions')),
            'created_at' => $this->created_at,
        ];
    }
}
