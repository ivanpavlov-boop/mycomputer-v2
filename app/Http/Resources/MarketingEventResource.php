<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'session_id' => $this->session_id,
            'event_name' => $this->event_name,
            'source' => $this->source,
            'payload' => $this->payload,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
