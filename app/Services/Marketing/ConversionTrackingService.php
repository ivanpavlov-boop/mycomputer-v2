<?php

namespace App\Services\Marketing;

use App\Models\ConversionLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\Marketing\Providers\GoogleAnalyticsProvider;
use App\Services\Marketing\Providers\MetaConversionApiProvider;

class ConversionTrackingService
{
    public function purchase(Order $order): array
    {
        $order->loadMissing('items');

        $payload = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'currency' => Product::CATALOG_CURRENCY,
            'value' => (float) $order->grand_total,
            'items' => $order->items->map(fn ($item): array => [
                'item_id' => $item->sku,
                'item_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => (float) $item->unit_price,
            ])->all(),
        ];

        return [
            $this->log($order, 'ga4', 'purchase', $payload),
            $this->log($order, 'meta', 'Purchase', $payload),
        ];
    }

    public function log(?Order $order, string $provider, string $eventName, array $payload): ConversionLog
    {
        $conversion = ConversionLog::query()->create([
            'order_id' => $order?->id,
            'provider' => $provider,
            'event_name' => $eventName,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        return $this->dispatch($conversion);
    }

    public function dispatch(ConversionLog $conversion): ConversionLog
    {
        $provider = $conversion->provider === 'meta'
            ? new MetaConversionApiProvider
            : new GoogleAnalyticsProvider;

        $response = $provider->convert($conversion);

        $conversion->update([
            'response' => $response,
            'status' => $response['status'] ?? 'skipped',
        ]);

        return $conversion->fresh();
    }
}
