<?php

namespace App\Services\Erp\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;
use BadMethodCallException;

abstract class UnsupportedErpProvider implements ErpProviderInterface
{
    public function testConnection(): array
    {
        return ['success' => false, 'unsupported' => true, 'message' => static::class.' is not implemented yet.'];
    }

    public function pushCustomer(Customer|User $customer): array
    {
        return $this->unsupported();
    }

    public function pushOrder(Order $order): array
    {
        return $this->unsupported();
    }

    public function pushPayment(Order $order): array
    {
        return $this->unsupported();
    }

    public function createInvoice(Order $order): array
    {
        return $this->unsupported();
    }

    public function pullStock(): array
    {
        return $this->unsupported();
    }

    public function pullProducts(): array
    {
        return $this->unsupported();
    }

    public function getDocument(string $externalId): array
    {
        return $this->unsupported();
    }

    public function cancelDocument(string $externalId): array
    {
        return $this->unsupported();
    }

    protected function unsupported(): array
    {
        throw new BadMethodCallException(static::class.' does not support ERP operations yet.');
    }
}
