<?php

namespace App\Services\Erp\Microinvest;

use App\Models\Order;

class MicroinvestOrderMapper
{
    public function __construct(
        private readonly MicroinvestConfig $config = new MicroinvestConfig,
    ) {}

    public function map(Order $order): array
    {
        $order->loadMissing(['items', 'bundleItems']);

        return [
            'document_type' => 'order',
            'order_number' => $order->order_number,
            'warehouse_code' => $this->config->warehouseCode,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'company_name' => $order->company_name,
                'vat_number' => $order->vat_number,
                'billing_address' => $order->billing_address,
                'shipping_address' => $order->shipping_address,
            ],
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'shipping_price' => (float) $order->shipping_price,
                'discount_total' => (float) $order->discount_total,
                'grand_total' => (float) $order->grand_total,
            ],
            'payment' => [
                'method' => $order->payment_method,
                'status' => $order->payment_status,
            ],
            'lines' => $order->items->map(fn ($item): array => [
                'type' => 'product',
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'vat_rate' => $this->config->vatSettings['default_rate'] ?? null,
            ])->values()->all(),
            'bundles' => $order->bundleItems->map(fn ($bundle): array => [
                'type' => 'bundle',
                'name' => $bundle->bundle_name,
                'quantity' => (int) $bundle->quantity,
                'total_price' => (float) $bundle->total_price,
                'selected_items' => $bundle->selected_items,
            ])->values()->all(),
        ];
    }
}
