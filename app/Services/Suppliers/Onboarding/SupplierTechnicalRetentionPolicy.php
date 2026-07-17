<?php

namespace App\Services\Suppliers\Onboarding;

final class SupplierTechnicalRetentionPolicy
{
    public const POLICY_KEY = 'supplier-technical-retention-policy-v1';

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'archived_catalog_product_retention' => 'indefinite',
            'cleanup_execution_allowed' => false,
            'critical_business_audit_retention' => 'indefinite',
            'current_supplier_offer_storage' => 'one_current_row_per_supplier_product_identity',
            'detailed_technical_import_log_retention_days' => 90,
            'meaningful_price_stock_history_only' => true,
            'policy_key' => self::POLICY_KEY,
            'raw_supplier_snapshot_retention_days' => 90,
            'scheduler_allowed' => false,
            'summarized_import_run_retention_months' => 24,
            'write_allowed' => false,
        ];
    }
}
