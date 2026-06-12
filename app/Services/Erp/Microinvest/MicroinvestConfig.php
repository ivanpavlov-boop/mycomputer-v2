<?php

namespace App\Services\Erp\Microinvest;

use App\Models\ErpProvider;

class MicroinvestConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?string $baseUrl = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $databaseCode = null,
        public readonly ?string $companyCode = null,
        public readonly ?string $warehouseCode = null,
        public readonly ?string $invoiceSeries = null,
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
            username: $credentials['username'] ?? null,
            password: $credentials['password'] ?? null,
            databaseCode: $settings['database_code'] ?? null,
            companyCode: $settings['company_code'] ?? null,
            warehouseCode: $settings['warehouse_code'] ?? null,
            invoiceSeries: $settings['invoice_series'] ?? null,
            paymentMethodMapping: $settings['payment_method_mapping'] ?? [],
            vatSettings: $settings['vat_settings'] ?? [],
        );
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && filled($this->baseUrl)
            && filled($this->username)
            && filled($this->password);
    }

    public function safeArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'configured' => $this->isConfigured(),
            'base_url_configured' => filled($this->baseUrl),
            'username_configured' => filled($this->username),
            'password_configured' => filled($this->password),
            'database_code' => $this->databaseCode,
            'company_code' => $this->companyCode,
            'warehouse_code' => $this->warehouseCode,
            'invoice_series' => $this->invoiceSeries,
            'payment_method_mapping_configured' => $this->paymentMethodMapping !== [],
            'vat_settings_configured' => $this->vatSettings !== [],
        ];
    }
}
