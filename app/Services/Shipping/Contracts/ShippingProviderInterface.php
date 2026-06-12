<?php

namespace App\Services\Shipping\Contracts;

use App\Models\Order;
use App\Models\OrderShipment;

interface ShippingProviderInterface
{
    public function getOffices(): array;

    public function calculatePrice(array $data): array;

    public function createShipment(Order $order, array $data): array;

    public function cancelShipment(OrderShipment $shipment): array;

    public function getTracking(OrderShipment $shipment): array;

    public function printLabel(OrderShipment $shipment): array;
}
