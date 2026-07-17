<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesign;

final class SupplierPreviewFeedProfileDesignRegistry
{
    public const APCOM_PROFILE = 'apcom-preview-feed-profile-v1';

    public const APCOM_PROFILE_V2 = 'apcom-preview-feed-profile-v2';

    public const APCOM_PROFILE_V3 = 'apcom-preview-feed-profile-v3';

    public function find(string $key): ?SupplierPreviewFeedProfileDesign
    {
        return match ($key) {
            self::APCOM_PROFILE => $this->apcomV1(),
            self::APCOM_PROFILE_V2 => $this->apcomV2(),
            self::APCOM_PROFILE_V3 => $this->apcomV3(),
            default => null,
        };
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

    public function apcomV2(): SupplierPreviewFeedProfileDesign
    {
        return new SupplierPreviewFeedProfileDesign(
            key: self::APCOM_PROFILE_V2,
            supplierKey: 'apcom',
            decisionRegisterKey: SupplierHumanDecisionRegistry::APCOM_REGISTER_V2,
            semanticsProfileKey: ApcomAuthoritativeBusinessPolicy::SEMANTICS_PROFILE_KEY,
            fieldMappings: [
                $this->field('supplier_sku', 'xml.product.partno', 'source_to_staging_identity', 'APCOM-ID-001'),
                $this->field('ean_gtin', 'xml.product.ean', 'diagnostic_only', 'APCOM-ID-002'),
                $this->field('mpn', null, 'unresolved', 'APCOM-MPN-001'),
                $this->field('product_name', 'xml.product.name', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('brand', 'xml.product.manufacturer', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('supplier_category', 'xml.product.category', 'presence_only', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
                $this->field('supplier_available_quantity_snapshot', 'xml.product.stock', 'internal_supplier_snapshot_metadata_only', 'APCOM-STOCK-001'),
                $this->field('quantity_cap', 'xml.product.stock', '100_means_100_or_more', 'APCOM-STOCK-001'),
                $this->field('availability', 'xml.product.stock with xml.product.eol', 'apcom-availability-policy-v1', 'APCOM-AVAILABILITY-001'),
                $this->field('lifecycle', 'xml.product.eol', 'canonical_supplier_lifecycle_status', 'APCOM-LIFECYCLE-001'),
                $this->field('supplier_purchase_price', 'xml.product.fd_price', 'supplier_purchase_price', 'APCOM-PRICE-001'),
                $this->field('dac_price', 'xml.product.dac_price', 'observable_price_candidate', 'APCOM-PRICE-001'),
                $this->field('currency', 'operator-confirmed commercial interpretation', 'EUR', 'APCOM-CURRENCY-001'),
                $this->field('vat', 'operator-confirmed commercial interpretation', 'exclusive', 'APCOM-VAT-001'),
                $this->field('green_tax', 'operator-confirmed commercial interpretation', 'included_in_fd_price', 'APCOM-GREEN-TAX-001'),
                $this->field('image_presence', 'xml.product.images.image', 'presence_only_no_import', 'APCOM-PROHIBIT-IMAGE-IMPORT-001'),
                $this->field('description_presence', 'xml.product.description', 'presence_only_no_overwrite', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'),
            ],
            actionMatrix: [
                $this->action('CREATE detection', 'source_only', 'APCOM-SOURCE-ONLY-001', 'preview classification only; handling remains pending'),
                $this->action('UPDATE comparison', 'exact_match', 'APCOM-PROHIBIT-UPDATE-SYNC-001', 'comparison only; UPDATE remains prohibited'),
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
            safetyPolicy: [
                'catalog_sync_allowed' => false,
                'human_review_required' => true,
                'import_allowed' => false,
                'profile_persistence_allowed' => false,
                'schedule_enablement_allowed' => false,
            ],
        );
    }

    public function apcomV3(): SupplierPreviewFeedProfileDesign
    {
        $v2 = $this->apcomV2();

        return new SupplierPreviewFeedProfileDesign(
            key: self::APCOM_PROFILE_V3,
            supplierKey: 'apcom',
            decisionRegisterKey: SupplierHumanDecisionRegistry::APCOM_REGISTER_V3,
            semanticsProfileKey: $v2->semanticsProfileKey,
            fieldMappings: [
                ...$v2->fieldMappings,
                $this->field('missing_offer_lifecycle', 'qualified full snapshot presence observation', 'supplier-offer-missing-policy-v1', 'APCOM-STAGING-ONLY-001'),
                $this->field('offer_reappearance', 'qualified full snapshot exact supplier SKU', 'supplier-offer-reappearance-policy-v1', 'APCOM-MISSING-OFFER-REAPPEARANCE-001'),
            ],
            actionMatrix: [
                ...$v2->actionMatrix,
                $this->action('Supplier offer lifecycle preview', 'qualified_missing_observation', 'APCOM-STAGING-ONLY-001', 'supplier-offer-only policy preview; no write, unlink, product visibility, or catalog action'),
                $this->action('Supplier offer reappearance preview', 'qualified_exact_sku_reappearance', 'APCOM-MISSING-OFFER-REAPPEARANCE-001', 'preview only; zero price and identifier conflicts remain blocked'),
            ],
            safetyPolicy: [
                ...$v2->safetyPolicy,
                'offer_lifecycle_write_allowed' => false,
                'product_visibility_write_allowed' => false,
                'retention_cleanup_allowed' => false,
                'storefront_visibility_runtime_allowed' => false,
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
