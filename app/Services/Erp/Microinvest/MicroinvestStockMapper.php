<?php

namespace App\Services\Erp\Microinvest;

class MicroinvestStockMapper
{
    public function __construct(
        private readonly MicroinvestConfig $config = new MicroinvestConfig,
    ) {}

    public function pullRequestPayload(): array
    {
        return [
            'warehouse_code' => $this->config->warehouseCode,
            'company_code' => $this->config->companyCode,
        ];
    }

    public function mapRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'sku' => $row['sku'] ?? $row['code'] ?? null,
            'external_product_id' => $row['external_product_id'] ?? $row['id'] ?? null,
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
            'warehouse_code' => $row['warehouse_code'] ?? $this->config->warehouseCode,
            'raw' => $row,
        ])->values()->all();
    }
}
