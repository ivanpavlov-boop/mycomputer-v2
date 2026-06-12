<?php

namespace App\Http\Resources;

use App\Services\Service\ServiceTicketService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_type' => $this->ticket_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'subject' => $this->subject,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'purchased_at' => $this->purchased_at?->toDateString(),
            'warranty_expires_at' => $this->warranty_expires_at?->toDateString(),
            'warranty' => app(ServiceTicketService::class)->warrantyStatus($this->resource),
            'diagnosis' => $this->diagnosis,
            'resolution' => $this->resolution,
            'work_performed' => $this->when($request->user()?->can('manage service tickets'), $this->work_performed),
            'parts_used' => $this->when($request->user()?->can('manage service tickets'), $this->parts_used),
            'repair_date' => $this->repair_date?->toDateString(),
            'refund_amount' => $this->refund_amount,
            'refund_date' => $this->refund_date?->toDateString(),
            'closed_at' => $this->closed_at,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ] : null),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'sku' => $this->product->sku,
                'warranty_months' => $this->product->warranty_months,
            ] : null),
            'messages' => ServiceTicketMessageResource::collection($this->whenLoaded('publicMessages')),
            'files' => ServiceTicketFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
