<?php

namespace App\Services\Erp\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;

class ManualErpProvider implements ErpProviderInterface
{
    public function testConnection(): array
    {
        return ['success' => true, 'manual' => true, 'message' => 'Manual ERP provider is available for admin processing.'];
    }

    public function pushCustomer(Customer|User $customer): array
    {
        return $this->manual('customer', $customer->getKey());
    }

    public function pushOrder(Order $order): array
    {
        return $this->manual('order', $order->id);
    }

    public function pushPayment(Order $order): array
    {
        return $this->manual('payment', $order->id);
    }

    public function createInvoice(Order $order): array
    {
        return $this->manual('invoice', $order->id);
    }

    public function pullStock(): array
    {
        return ['success' => true, 'manual' => true, 'status' => 'skipped', 'items' => [], 'message' => 'Manual provider does not pull stock automatically.'];
    }

    public function pullProducts(): array
    {
        return ['success' => true, 'manual' => true, 'status' => 'skipped', 'items' => [], 'message' => 'Manual provider does not pull products automatically.'];
    }

    public function getDocument(string $externalId): array
    {
        return ['success' => true, 'manual' => true, 'external_id' => $externalId, 'message' => 'Document must be attached manually.'];
    }

    public function cancelDocument(string $externalId): array
    {
        return ['success' => true, 'manual' => true, 'external_id' => $externalId, 'message' => 'Document cancellation must be completed manually.'];
    }

    private function manual(string $entity, int|string $id): array
    {
        return [
            'success' => true,
            'manual' => true,
            'status' => 'pending',
            'entity' => $entity,
            'entity_id' => $id,
            'message' => 'Manual ERP provider queued this item for admin processing.',
        ];
    }
}
