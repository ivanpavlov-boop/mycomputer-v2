<?php

namespace App\Services\Suppliers\Onboarding;

use App\Enums\Suppliers\Onboarding\CanonicalPublicAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierLifecycleStatus;

final class ApcomAuthoritativeBusinessPolicy
{
    public const SEMANTICS_PROFILE_KEY = 'apcom-approved-business-semantics-v2';

    public const PUBLIC_QUANTITY_POLICY_KEY = 'public-supplier-quantity-policy-v1';

    /** @return array<string, mixed> */
    public function canonicalStatusModel(): array
    {
        return [
            'availability_statuses' => array_map(static fn (CanonicalSupplierAvailabilityStatus $status): string => $status->value, CanonicalSupplierAvailabilityStatus::cases()),
            'computed_public_statuses' => array_map(static fn (CanonicalPublicAvailabilityStatus $status): string => $status->value, CanonicalPublicAvailabilityStatus::cases()),
            'lifecycle_statuses' => array_map(static fn (CanonicalSupplierLifecycleStatus $status): string => $status->value, CanonicalSupplierLifecycleStatus::cases()),
            'lifecycle_is_separate_from_availability' => true,
            'preview_only' => true,
            'supplier_neutral' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function publicQuantityPolicy(): array
    {
        return [
            'catalog_write_allowed' => false,
            'exact_quantity_is_customer_facing_promise' => false,
            'internal_supplier_quantity_allowed' => true,
            'policy_key' => self::PUBLIC_QUANTITY_POLICY_KEY,
            'public_exact_quantity_allowed' => false,
            'snapshot_is_realtime_guarantee' => false,
            'storefront_behavior_changed' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function priceMappingPreview(): array
    {
        return [
            'automatic_catalog_price_write_allowed' => false,
            'automatic_staging_price_write_allowed' => false,
            'currency' => 'EUR',
            'dac_price' => [
                'automatic_role_allowed' => false,
                'role' => 'observable_price_candidate',
                'selected_supplier_purchase_price' => false,
            ],
            'evidence_codes' => [
                'operator_portal_crosscheck_fd_price_exact_match',
                'operator_confirmed_currency_eur',
                'operator_confirmed_vat_exclusive',
            ],
            'evidence_type' => 'operator_confirmed_business_evidence',
            'selected_source_field' => 'xml.product.fd_price',
            'selected_supplier_purchase_price' => true,
            'supplier_purchase_price_role' => 'supplier_purchase_price',
            'update_sync_allowed' => false,
            'vat_treatment' => 'exclusive',
            'zero_price_handling' => 'review_only',
        ];
    }

    /** @return array<string, mixed> */
    public function greenTaxPolicy(): array
    {
        return [
            'automatic_separate_green_tax_addition_allowed' => false,
            'contradiction_requires_review' => true,
            'evidence_code' => 'operator_confirmed_green_tax_included',
            'evidence_type' => 'operator_confirmed_business_evidence',
            'included_in_fd_price' => true,
            'missing_greentax_does_not_add_second_charge' => true,
            'supplier_cost_without_vat_remains_fd_price' => true,
        ];
    }
}
