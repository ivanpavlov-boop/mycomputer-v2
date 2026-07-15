<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesign;

final class SupplierPreviewFeedProfileDesignRegistry
{
    public const APCOM_PROFILE = 'apcom-preview-feed-profile-v1';

    public function find(string $key): ?SupplierPreviewFeedProfileDesign
    {
        return $key === self::APCOM_PROFILE ? $this->apcomV1() : null;
    }

    public function apcomV1(): SupplierPreviewFeedProfileDesign
    {
        return new SupplierPreviewFeedProfileDesign(
            key: self::APCOM_PROFILE,
            supplierKey: 'apcom',
            decisionRegisterKey: SupplierHumanDecisionRegistry::APCOM_REGISTER,
            semanticsProfileKey: 'apcom-observed-stock-v1',
            fieldMappings: [
                $this->field('supplier_sku', 'xml.product.partno', 'source_to_staging_identity', 'APCOM-ID-001'),
                $this->field('ean_gtin', 'xml.product.ean', 'diagnostic_only', 'APCOM-ID-002'),
                $this->field('mpn', null, 'unresolved', 'APCOM-MPN-001'),
                $this->field('product_name', 'xml.product.name', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('brand', 'xml.product.manufacturer', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('supplier_category', 'xml.product.category', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('observed_stock', 'xml.product.stock', 'unresolved_numeric_observation', 'APCOM-STOCK-001'),
                $this->field('quantity', null, 'unresolved', 'APCOM-QUANTITY-001'),
                $this->field('availability', null, 'unresolved', 'APCOM-AVAILABILITY-001'),
                $this->field('eol', 'xml.product.eol', 'review_only', 'APCOM-LIFECYCLE-001'),
                $this->field('price_candidates', 'xml.product.dac_price or xml.product.fd_price', 'review_only', 'APCOM-PRICE-001'),
                $this->field('selected_price', null, 'unresolved', 'APCOM-PRICE-001'),
                $this->field('currency', null, 'unresolved', 'APCOM-CURRENCY-001'),
                $this->field('vat', null, 'unresolved', 'APCOM-VAT-001'),
                $this->field('green_tax', 'xml.product.greentax', 'unresolved', 'APCOM-GREEN-TAX-001'),
                $this->field('image_presence', 'xml.product.images.image', 'presence_only_no_import', 'APCOM-PROHIBIT-IMAGE-IMPORT-001'),
                $this->field('description_presence', 'xml.product.description', 'presence_only_no_overwrite', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('cncode', 'xml.product.cncode', 'presence_only_no_mapping', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('group', 'xml.product.group', 'presence_only_no_mapping', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
            ],
            actionMatrix: [
                $this->action('CREATE detection', 'source_only', 'APCOM-SOURCE-ONLY-001', 'preview classification only'),
                $this->action('UPDATE comparison', 'exact_match', 'APCOM-PRICE-001', 'aggregate comparison only; commercial semantics unresolved'),
                $this->action('DELETE classification', 'staging_only', 'APCOM-STAGING-ONLY-001', 'classification only; deletion prohibited'),
                $this->action('LINK diagnostic', 'matched_unlinked', 'APCOM-ID-002', 'diagnostic only; linking prohibited'),
                $this->action('UNLINK classification', 'staging_only_linked', 'APCOM-LINKED-STAGING-ONLY-001', 'classification only; unlinking prohibited'),
                $this->action('Content overwrite', 'content_fields', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001', 'prohibited'),
                $this->action('Image import', 'image_presence', 'APCOM-PROHIBIT-IMAGE-IMPORT-001', 'prohibited'),
                $this->action('Schedule enablement', 'supplier_schedule', 'APCOM-PROHIBIT-SCHEDULE-001', 'prohibited'),
                $this->action('Automatic import', 'supplier_import', 'APCOM-PROHIBIT-AUTO-IMPORT-001', 'prohibited'),
                $this->action('Sync All', 'catalog_sync', 'APCOM-PROHIBIT-SYNC-ALL-001', 'prohibited'),
                $this->action('Automatic sync', 'catalog_sync', 'APCOM-PROHIBIT-AUTO-SYNC-001', 'prohibited'),
                $this->action('UPDATE sync', 'catalog_sync', 'APCOM-PROHIBIT-UPDATE-SYNC-001', 'prohibited'),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function field(string $field, ?string $sourcePath, string $role, string $decisionId): array
    {
        return [
            'field' => $field,
            'source_path' => $sourcePath,
            'proposed_role' => $role,
            'decision_id' => $decisionId,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'staging_write_allowed' => false,
            'raw_values_emitted' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function action(string $action, string $classification, string $decisionId, string $reason): array
    {
        return [
            'action' => $action,
            'classification' => $classification,
            'decision_id' => $decisionId,
            'reason' => $reason,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'staging_write_allowed' => false,
            'profile_persistence_allowed' => false,
        ];
    }
}
