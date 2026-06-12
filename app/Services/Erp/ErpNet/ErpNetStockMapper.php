<?php

namespace App\Services\Erp\ErpNet;

class ErpNetStockMapper
{
    public function __construct(
        private readonly ErpNetConfig $config = new ErpNetConfig,
    ) {}

    public function pullRequestPayload(): array
    {
        return [
            'company_id' => $this->config->companyId,
            'warehouse_id' => $this->config->warehouseId,
            'price_list_id' => $this->config->priceListId,
        ];
    }

    public function mapRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'sku' => $row['sku'] ?? $row['part_number'] ?? null,
            'external_product_id' => $row['external_product_id'] ?? $row['product_id'] ?? null,
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
            'warehouse_id' => $row['warehouse_id'] ?? $this->config->warehouseId,
            'raw' => $row,
        ])->values()->all();
    }
}
