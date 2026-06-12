<?php

namespace App\Services\Erp\Contracts;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;

interface ErpProviderInterface
{
    public function testConnection(): array;

    public function pushCustomer(Customer|User $customer): array;

    public function pushOrder(Order $order): array;

    public function pushPayment(Order $order): array;

    public function createInvoice(Order $order): array;

    public function pullStock(): array;

    public function pullProducts(): array;

    public function getDocument(string $externalId): array;

    public function cancelDocument(string $externalId): array;
}
