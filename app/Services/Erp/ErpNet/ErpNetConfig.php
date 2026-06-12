<?php

namespace App\Services\Erp\ErpNet;

use App\Models\ErpProvider;

class ErpNetConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?string $baseUrl = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $companyId = null,
        public readonly ?string $warehouseId = null,
        public readonly ?string $priceListId = null,
        public readonly ?string $invoiceDocumentType = null,
        public readonly array $paymentMethodMapping = [],
        public readonly array $vatSettings = [],
    ) {}

    public static function fromProvider(?ErpProvider $provider): self
    {
        $settings = $provider?->settings ?? [];
        $credentials = $provider?->credentials ?? [];

        return new self(
            enabled: (bool) ($settings['enabled'] ?? false),
            baseUrl: $settings['base_url'] ?? null,
            apiKey: $credentials['api_key'] ?? null,
            companyId: $settings['company_id'] ?? null,
            warehouseId: $settings['warehouse_id'] ?? null,
            priceListId: $settings['price_list_id'] ?? null,
            invoiceDocumentType: $settings['invoice_document_type'] ?? null,
            paymentMethodMapping: $settings['payment_method_mapping'] ?? [],
            vatSettings: $settings['vat_settings'] ?? [],
        );
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && filled($this->baseUrl)
            && filled($this->apiKey);
    }

    public function safeArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'configured' => $this->isConfigured(),
            'base_url_configured' => filled($this->baseUrl),
            'api_key_configured' => filled($this->apiKey),
            'company_id' => $this->companyId,
            'warehouse_id' => $this->warehouseId,
            'price_list_id' => $this->priceListId,
            'invoice_document_type' => $this->invoiceDocumentType,
            'payment_method_mapping_configured' => $this->paymentMethodMapping !== [],
            'vat_settings_configured' => $this->vatSettings !== [],
        ];
    }
}
