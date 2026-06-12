<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'internal_note' => $this->when($request->user()?->can('manage service tickets'), $this->internal_note),
            'author' => $this->admin?->name ?? $this->user?->name,
            'created_at' => $this->created_at,
        ];
    }
}
