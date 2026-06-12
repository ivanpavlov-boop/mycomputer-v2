<?php

namespace App\Services\Marketing;

use App\Models\MarketingEvent;
use App\Models\User;
use Illuminate\Support\Arr;

class MarketingEventService
{
    public function log(string $eventName, string $source, array $payload = [], ?User $user = null, ?string $sessionId = null): MarketingEvent
    {
        return MarketingEvent::query()->create([
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'event_name' => $eventName,
            'source' => in_array($source, MarketingEvent::SOURCES, true) ? $source : 'internal',
            'payload' => $this->sanitizePayload($payload),
            'status' => 'logged',
        ]);
    }

    private function sanitizePayload(array $payload): array
    {
        return Arr::except($payload, [
            'purchase_price',
            'supplier_credentials',
            'source_payload',
            'password',
            'token',
            'access_token',
            'secret',
        ]);
    }
}
