<?php

namespace App\Data\Suppliers\Onboarding;

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
     */
    public function __construct(
        public string $key,
        public string $supplierKey,
        public string $recordPath,
        public array $fieldMap,
        public array $requiredFields,
        public array $unresolvedFields,
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
        );
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
            'stock_is_not_quantity' => true,
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
