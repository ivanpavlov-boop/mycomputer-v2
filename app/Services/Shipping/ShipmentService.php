<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShippingProvider;

class ShipmentService
{
    public function __construct(private readonly ShippingService $shippingService) {}

    public function create(Order $order, array $data): OrderShipment
    {
        $provider = $data['shipping_provider_id'] ? ShippingProvider::query()->find($data['shipping_provider_id']) : null;
        $providerResponse = $provider ? $this->shippingService->provider($provider)->createShipment($order, $data) : [];

        return $order->shipments()->create([
            'shipping_provider_id' => $data['shipping_provider_id'] ?? null,
            'shipping_method_id' => $data['shipping_method_id'] ?? null,
            'tracking_number' => $providerResponse['tracking_number'] ?? null,
            'label_path' => $providerResponse['label_path'] ?? null,
            'office_id' => $data['office_id'] ?? null,
            'delivery_type' => $data['delivery_type'],
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $order->customer_phone,
            'city' => $data['city'],
            'postcode' => $data['postcode'] ?? null,
            'address' => $data['address'] ?? null,
            'price' => $data['price'],
            'status' => $providerResponse['status'] ?? 'pending',
            'raw_request' => $data,
            'raw_response' => $providerResponse['raw_response'] ?? $providerResponse,
        ]);
    }
}
