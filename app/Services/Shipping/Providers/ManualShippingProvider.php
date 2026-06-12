<?php

namespace App\Services\Shipping\Providers;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Shipping\Contracts\ShippingProviderInterface;

class ManualShippingProvider implements ShippingProviderInterface
{
    public function getOffices(): array
    {
        return [];
    }

    public function calculatePrice(array $data): array
    {
        return [
            'price' => (float) ($data['method_price'] ?? 0),
            'estimated_delivery' => '1-3 работни дни',
            'provider' => $data['provider'] ?? 'manual',
            'method' => $data['shipping_method_code'] ?? 'manual',
        ];
    }

    public function createShipment(Order $order, array $data): array
    {
        return [
            'tracking_number' => null,
            'label_path' => null,
            'status' => 'pending',
            'raw_response' => ['mode' => 'manual_placeholder'],
        ];
    }

    public function cancelShipment(OrderShipment $shipment): array
    {
        return ['status' => 'cancelled', 'message' => 'Manual shipment cancellation placeholder.'];
    }

    public function getTracking(OrderShipment $shipment): array
    {
        return ['status' => $shipment->status, 'events' => []];
    }

    public function printLabel(OrderShipment $shipment): array
    {
        return ['label_path' => $shipment->label_path, 'message' => 'Manual label placeholder.'];
    }
}
