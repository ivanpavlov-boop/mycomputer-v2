<?php

namespace App\Services\Erp\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;
use App\Services\Erp\ErpNet\ErpNetApiClient;
use App\Services\Erp\ErpNet\ErpNetConfig;
use App\Services\Erp\ErpNet\ErpNetCustomerMapper;
use App\Services\Erp\ErpNet\ErpNetInvoiceMapper;
use App\Services\Erp\ErpNet\ErpNetOrderMapper;
use App\Services\Erp\ErpNet\ErpNetPaymentMapper;
use App\Services\Erp\ErpNet\ErpNetStockMapper;

class ErpNetProvider implements ErpProviderInterface
{
    private readonly ErpNetApiClient $client;

    private readonly ErpNetCustomerMapper $customerMapper;

    private readonly ErpNetOrderMapper $orderMapper;

    private readonly ErpNetInvoiceMapper $invoiceMapper;

    private readonly ErpNetPaymentMapper $paymentMapper;

    private readonly ErpNetStockMapper $stockMapper;

    public function __construct(
        private readonly ErpNetConfig $config = new ErpNetConfig,
        ?ErpNetApiClient $client = null,
        ?ErpNetCustomerMapper $customerMapper = null,
        ?ErpNetOrderMapper $orderMapper = null,
        ?ErpNetInvoiceMapper $invoiceMapper = null,
        ?ErpNetPaymentMapper $paymentMapper = null,
        ?ErpNetStockMapper $stockMapper = null,
    ) {
        $this->client = $client ?? new ErpNetApiClient($this->config);
        $this->customerMapper = $customerMapper ?? new ErpNetCustomerMapper;
        $this->orderMapper = $orderMapper ?? new ErpNetOrderMapper($this->config);
        $this->invoiceMapper = $invoiceMapper ?? new ErpNetInvoiceMapper($this->config, $this->orderMapper);
        $this->paymentMapper = $paymentMapper ?? new ErpNetPaymentMapper($this->config);
        $this->stockMapper = $stockMapper ?? new ErpNetStockMapper($this->config);
    }

    public function testConnection(): array
    {
        return $this->client->testConnection();
    }

    public function pushCustomer(Customer|User $customer): array
    {
        return $this->client->pushCustomer($this->customerMapper->map($customer));
    }

    public function pushOrder(Order $order): array
    {
        return $this->client->pushOrder($this->orderMapper->map($order));
    }

    public function pushPayment(Order $order): array
    {
        return $this->client->pushPayment($this->paymentMapper->map($order));
    }

    public function createInvoice(Order $order): array
    {
        return $this->client->createInvoice($this->invoiceMapper->map($order));
    }

    public function pullStock(): array
    {
        return $this->client->pullStock($this->stockMapper->pullRequestPayload());
    }

    public function pullProducts(): array
    {
        return $this->client->pullProducts();
    }

    public function getDocument(string $externalId): array
    {
        return $this->client->getDocument($externalId);
    }

    public function cancelDocument(string $externalId): array
    {
        return $this->client->cancelDocument($externalId);
    }
}
