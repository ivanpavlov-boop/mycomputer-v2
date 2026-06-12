<?php

namespace App\Services\Erp\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;

class MockErpProvider implements ErpProviderInterface
{
    public function testConnection(): array
    {
        return ['success' => true, 'provider' => 'mock', 'message' => 'Mock ERP provider is healthy.'];
    }

    public function pushCustomer(Customer|User $customer): array
    {
        return [
            'success' => true,
            'external_id' => 'MOCK-CUST-'.$customer->getKey(),
            'external_company_id' => filled($customer->company_name ?? null) ? 'MOCK-COMP-'.$customer->getKey() : null,
        ];
    }

    public function pushOrder(Order $order): array
    {
        return ['success' => true, 'external_id' => 'MOCK-ORDER-'.$order->order_number];
    }

    public function pushPayment(Order $order): array
    {
        return ['success' => true, 'external_id' => 'MOCK-PAYMENT-'.$order->order_number, 'status' => $order->payment_status];
    }

    public function createInvoice(Order $order): array
    {
        return [
            'success' => true,
            'external_id' => 'MOCK-INV-'.$order->order_number,
            'document_number' => 'INV-'.$order->order_number,
            'document_date' => now()->toDateString(),
        ];
    }

    public function pullStock(): array
    {
        return [
            'success' => true,
            'items' => [
                ['external_sku' => 'MOCK-SKU-1', 'quantity' => 10],
            ],
        ];
    }

    public function pullProducts(): array
    {
        return [
            'success' => true,
            'items' => [
                ['external_sku' => 'MOCK-SKU-1', 'name' => 'Mock ERP Product'],
            ],
        ];
    }

    public function getDocument(string $externalId): array
    {
        return ['success' => true, 'external_id' => $externalId, 'content' => null];
    }

    public function cancelDocument(string $externalId): array
    {
        return ['success' => true, 'external_id' => $externalId, 'status' => 'cancelled'];
    }
}
