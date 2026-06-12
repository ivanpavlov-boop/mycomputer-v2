<?php

namespace App\Services\Erp\Microinvest;

use App\Models\Order;

class MicroinvestInvoiceMapper
{
    public function __construct(
        private readonly MicroinvestConfig $config = new MicroinvestConfig,
        private readonly MicroinvestOrderMapper $orderMapper = new MicroinvestOrderMapper,
    ) {}

    public function map(Order $order): array
    {
        return [
            'document_type' => 'invoice',
            'invoice_series' => $this->config->invoiceSeries,
            'company_code' => $this->config->companyCode,
            'order' => $this->orderMapper->map($order),
        ];
    }
}
