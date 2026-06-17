<?php

namespace App\Services\Erp\ErpNet;

use App\Models\Order;

class ErpNetOrderMapper
{
    public function __construct(
        private readonly ErpNetConfig $config = new ErpNetConfig,
    ) {}

    public function map(Order $order): array
    {
        $order->loadMissing(['items', 'bundleItems']);

        return [
            'document_type' => 'sales_order',
            'order_number' => $order->order_number,
            'company_id' => $this->config->companyId,
            'warehouse_id' => $this->config->warehouseId,
            'price_list_id' => $this->config->priceListId,
            'customer' => [
                'display_name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'company_name' => $order->company_name,
                'tax_number' => $order->vat_number,
                'billing_address' => $order->billing_address,
                'shipping_address' => $order->shipping_address,
            ],
            'amounts' => [
                'subtotal' => (float) $order->subtotal,
                'shipping' => (float) $order->shipping_price,
                'discount' => (float) $order->discount_total,
                'total' => (float) $order->grand_total,
                'currency' => 'EUR',
            ],
            'payment' => [
                'method' => $order->payment_method,
                'status' => $order->payment_status,
            ],
            'lines' => $order->items->map(fn ($item): array => [
                'line_type' => 'item',
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->total_price,
                'vat_rate' => $this->config->vatSettings['default_rate'] ?? null,
            ])->values()->all(),
            'bundles' => $order->bundleItems->map(fn ($bundle): array => [
                'line_type' => 'bundle',
                'name' => $bundle->bundle_name,
                'quantity' => (int) $bundle->quantity,
                'line_total' => (float) $bundle->total_price,
                'selected_items' => $bundle->selected_items,
            ])->values()->all(),
        ];
    }
}
