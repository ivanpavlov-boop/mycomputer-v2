<?php

namespace App\Services\Erp\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;
use App\Services\Erp\Microinvest\MicroinvestApiClient;
use App\Services\Erp\Microinvest\MicroinvestConfig;
use App\Services\Erp\Microinvest\MicroinvestCustomerMapper;
use App\Services\Erp\Microinvest\MicroinvestInvoiceMapper;
use App\Services\Erp\Microinvest\MicroinvestOrderMapper;
use App\Services\Erp\Microinvest\MicroinvestPaymentMapper;
use App\Services\Erp\Microinvest\MicroinvestStockMapper;

class MicroinvestProvider implements ErpProviderInterface
{
    private readonly MicroinvestApiClient $client;

    private readonly MicroinvestCustomerMapper $customerMapper;

    private readonly MicroinvestOrderMapper $orderMapper;

    private readonly MicroinvestInvoiceMapper $invoiceMapper;

    private readonly MicroinvestPaymentMapper $paymentMapper;

    private readonly MicroinvestStockMapper $stockMapper;

    public function __construct(
        private readonly MicroinvestConfig $config = new MicroinvestConfig,
        ?MicroinvestApiClient $client = null,
        ?MicroinvestCustomerMapper $customerMapper = null,
        ?MicroinvestOrderMapper $orderMapper = null,
        ?MicroinvestInvoiceMapper $invoiceMapper = null,
        ?MicroinvestPaymentMapper $paymentMapper = null,
        ?MicroinvestStockMapper $stockMapper = null,
    ) {
        $this->client = $client ?? new MicroinvestApiClient($this->config);
        $this->customerMapper = $customerMapper ?? new MicroinvestCustomerMapper;
        $this->orderMapper = $orderMapper ?? new MicroinvestOrderMapper($this->config);
        $this->invoiceMapper = $invoiceMapper ?? new MicroinvestInvoiceMapper($this->config, $this->orderMapper);
        $this->paymentMapper = $paymentMapper ?? new MicroinvestPaymentMapper($this->config);
        $this->stockMapper = $stockMapper ?? new MicroinvestStockMapper($this->config);
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
