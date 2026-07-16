<?php

namespace App\Data\Suppliers\Onboarding;

use App\Services\Suppliers\Onboarding\ApcomAuthoritativeBusinessPolicy;
use App\Services\Suppliers\Onboarding\ApcomAvailabilityMapper;
use JsonSerializable;

/**
 * Versioned, operator-confirmed source semantics. This is not import mapping.
 */
final readonly class SupplierSourceFieldSemanticsProfile implements JsonSerializable
{
    /**
     * @param  array<string, string|array<int, string>|null>  $fieldMap
     * @param  array<int, string>  $requiredFields
     * @param  array<int, string>  $unresolvedFields
     * @param  array<string, bool|int|string|null>  $stockSemantics
     */
    public function __construct(
        public string $key,
        public string $supplierKey,
        public string $recordPath,
        public array $fieldMap,
        public array $requiredFields,
        public array $unresolvedFields,
        public array $stockSemantics,
    ) {}

    public static function apcomOfficialV1(): self
    {
        return new self(
            key: 'apcom-official-v1',
            supplierKey: 'apcom',
            recordPath: 'xml.product',
            fieldMap: [
                'supplier_sku' => 'partno',
                'ean' => 'ean',
                'product_name' => 'name',
                'brand' => 'manufacturer',
                'supplier_category' => 'category',
                'stock_status' => 'stock',
                'lifecycle_eol' => 'eol',
                'promo' => 'promo',
                'news' => 'news',
                'image_paths' => ['images', 'images.image'],
                'cn_code' => 'cncode',
                'dimensions' => ['width', 'height', 'depth', 'weight'],
                'supplier_group' => 'group',
                'price_candidates' => ['dac_price', 'fd_price'],
                'mpn' => null,
                'quantity' => null,
                'currency' => null,
                'vat' => null,
                'selected_price' => null,
                'greentax' => 'greentax',
            ],
            requiredFields: ['partno', 'ean', 'stock', 'eol', 'dac_price', 'fd_price'],
            unresolvedFields: ['mpn', 'quantity', 'currency', 'vat', 'selected_price'],
            stockSemantics: [
                'official_stock_claim' => 'binary_0_1_availability',
                'observed_stock_contract' => null,
                'observed_stock_semantic_status' => null,
                'semantics_discrepancy' => false,
                'semantic_resolution' => 'official_binary_claim',
                'automatic_quantity_mapping_allowed' => false,
                'automatic_availability_mapping_allowed' => false,
                'requires_human_review' => true,
                'validation_mode' => 'binary_0_1',
            ],
        );
    }

    public static function apcomObservedStockV1(): self
    {
        return new self(
            key: 'apcom-observed-stock-v1',
            supplierKey: 'apcom',
            recordPath: 'xml.product',
            fieldMap: [
                'supplier_sku' => 'partno',
                'ean' => 'ean',
                'product_name' => 'name',
                'brand' => 'manufacturer',
                'supplier_category' => 'category',
                'observed_stock' => 'stock',
                'quantity' => null,
                'availability' => null,
                'lifecycle_eol' => 'eol',
                'promo' => 'promo',
                'news' => 'news',
                'image_paths' => ['images', 'images.image'],
                'cn_code' => 'cncode',
                'dimensions' => ['width', 'height', 'depth', 'weight'],
                'supplier_group' => 'group',
                'price_candidates' => ['dac_price', 'fd_price'],
                'mpn' => null,
                'currency' => null,
                'vat' => null,
                'selected_price' => null,
                'greentax' => 'greentax',
            ],
            requiredFields: ['partno', 'ean', 'stock', 'eol', 'dac_price', 'fd_price'],
            unresolvedFields: ['mpn', 'quantity', 'availability', 'currency', 'vat', 'selected_price', 'greentax'],
            stockSemantics: [
                'official_stock_claim' => 'binary_0_1_availability',
                'observed_stock_contract' => 'non_negative_integer_numeric',
                'observed_stock_semantic_status' => 'unresolved_numeric',
                'semantics_discrepancy' => true,
                'semantic_resolution' => 'unresolved',
                'automatic_quantity_mapping_allowed' => false,
                'automatic_availability_mapping_allowed' => false,
                'requires_human_review' => true,
                'validation_mode' => 'non_negative_integer_numeric',
            ],
        );
    }

    public static function apcomApprovedBusinessSemanticsV2(): self
    {
        return new self(
            key: 'apcom-approved-business-semantics-v2',
            supplierKey: 'apcom',
            recordPath: 'xml.product',
            fieldMap: [
                'supplier_sku' => 'partno',
                'ean' => 'ean',
                'product_name' => 'name',
                'brand' => 'manufacturer',
                'supplier_category' => 'category',
                'observed_stock' => 'stock',
                'quantity' => null,
                'availability' => null,
                'lifecycle_eol' => 'eol',
                'promo' => 'promo',
                'news' => 'news',
                'image_paths' => ['images', 'images.image'],
                'cn_code' => 'cncode',
                'dimensions' => ['width', 'height', 'depth', 'weight'],
                'supplier_group' => 'group',
                'price_candidates' => ['dac_price', 'fd_price'],
                'mpn' => null,
                'currency' => null,
                'vat' => null,
                'selected_price' => 'fd_price',
                'greentax' => 'greentax',
            ],
            requiredFields: ['partno', 'stock', 'eol', 'dac_price', 'fd_price'],
            unresolvedFields: ['mpn', 'quantity', 'missing_product_handling', 'snapshot_freshness_threshold', 'cart_limit_policy'],
            stockSemantics: [
                'automatic_availability_mapping_allowed' => false,
                'automatic_quantity_mapping_allowed' => false,
                'canonical_availability_policy_key' => ApcomAvailabilityMapper::POLICY_KEY,
                'observed_stock_contract' => 'non_negative_integer_numeric',
                'observed_stock_semantic_status' => 'approved_supplier_snapshot',
                'official_stock_claim' => 'supplier_available_quantity_snapshot',
                'public_exact_quantity_allowed' => false,
                'public_quantity_policy_key' => ApcomAuthoritativeBusinessPolicy::PUBLIC_QUANTITY_POLICY_KEY,
                'quantity_cap' => ApcomAvailabilityMapper::QUANTITY_CAP,
                'quantity_cap_meaning' => '100_or_more',
                'requires_human_review' => true,
                'semantic_resolution' => 'approved_supplier_snapshot',
                'semantics_discrepancy' => false,
                'validation_mode' => 'non_negative_integer_numeric',
            ],
        );
    }

    public function usesObservedNumericStockContract(): bool
    {
        return ($this->stockSemantics['validation_mode'] ?? null) === 'non_negative_integer_numeric';
    }

    public function hasApprovedSupplierAvailabilitySemantics(): bool
    {
        return ($this->stockSemantics['semantic_resolution'] ?? null) === 'approved_supplier_snapshot';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'key' => $this->key,
            'supplier_key' => $this->supplierKey,
            'record_path' => $this->recordPath,
            'field_map' => $this->fieldMap,
            'required_fields' => $this->requiredFields,
            'unresolved_fields' => $this->unresolvedFields,
            'source_format' => 'xml',
            'supplier_sku_path' => $this->fieldMap['supplier_sku'] ?? null,
            'ean_path' => $this->fieldMap['ean'] ?? null,
            'mpn_path' => $this->fieldMap['mpn'] ?? null,
            'product_name_path' => $this->fieldMap['product_name'] ?? null,
            'brand_path' => $this->fieldMap['brand'] ?? null,
            'supplier_category_path' => $this->fieldMap['supplier_category'] ?? null,
            'observed_stock_path' => $this->fieldMap['observed_stock'] ?? null,
            'quantity_path' => $this->fieldMap['quantity'] ?? null,
            'availability_path' => $this->fieldMap['availability'] ?? null,
            'eol_path' => $this->fieldMap['lifecycle_eol'] ?? null,
            'price_candidate_paths' => $this->fieldMap['price_candidates'] ?? [],
            'selected_price_path' => $this->fieldMap['selected_price'] ?? null,
            'currency_path' => $this->fieldMap['currency'] ?? null,
            'vat_treatment' => $this->fieldMap['vat'] ?? 'unresolved',
            'greentax_path' => $this->fieldMap['greentax'] ?? null,
            'image_paths' => $this->fieldMap['image_paths'] ?? [],
            'cncode_path' => $this->fieldMap['cn_code'] ?? null,
            'group_path' => $this->fieldMap['supplier_group'] ?? null,
            'stock_semantics' => $this->stockSemantics,
            'official_stock_claim' => $this->stockSemantics['official_stock_claim'] ?? null,
            'observed_stock_contract' => $this->stockSemantics['observed_stock_contract'] ?? null,
            'observed_stock_semantic_status' => $this->stockSemantics['observed_stock_semantic_status'] ?? null,
            'semantics_discrepancy' => $this->stockSemantics['semantics_discrepancy'] ?? false,
            'semantic_resolution' => $this->stockSemantics['semantic_resolution'] ?? 'unresolved',
            'automatic_quantity_mapping_allowed' => $this->stockSemantics['automatic_quantity_mapping_allowed'] ?? false,
            'automatic_availability_mapping_allowed' => $this->stockSemantics['automatic_availability_mapping_allowed'] ?? false,
            'requires_human_review' => $this->stockSemantics['requires_human_review'] ?? true,
            'stock_is_not_quantity' => true,
            'stock_is_not_binary_availability' => $this->usesObservedNumericStockContract(),
            'partno_is_not_mpn' => true,
            'cncode_is_not_identifier' => true,
            'price_selection_resolved' => false,
            'previous_quantity_to_stock_heuristic_superseded' => true,
            'image_paths_presence_only' => true,
            'semantics_are_read_only' => true,
            'semantics_profile_persisted' => false,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
