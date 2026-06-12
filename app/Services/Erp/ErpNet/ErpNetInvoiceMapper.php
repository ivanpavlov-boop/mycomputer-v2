<?php

namespace App\Services\Erp\ErpNet;

use App\Models\Order;

class ErpNetInvoiceMapper
{
    public function __construct(
        private readonly ErpNetConfig $config = new ErpNetConfig,
        private readonly ErpNetOrderMapper $orderMapper = new ErpNetOrderMapper,
    ) {}

    public function map(Order $order): array
    {
        return [
            'document_type' => 'invoice',
            'invoice_document_type' => $this->config->invoiceDocumentType,
            'company_id' => $this->config->companyId,
            'order' => $this->orderMapper->map($order),
        ];
    }
}
