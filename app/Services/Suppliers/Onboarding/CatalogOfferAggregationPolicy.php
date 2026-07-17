<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogOfferAggregationInput;
use App\Data\Suppliers\Onboarding\CatalogOfferAggregationResult;

final class CatalogOfferAggregationPolicy
{
    public const POLICY_KEY = 'catalog-offer-aggregation-policy-v1';

    /** @var array<int, string> */
    private const ACTIVE_STATUSES = ['in_stock', 'limited', 'on_request', 'last_units'];

    /** @var array<int, string> */
    private const STATUS_PRIORITY = ['in_stock', 'limited', 'on_request', 'last_units'];

    public function preview(CatalogOfferAggregationInput $input): CatalogOfferAggregationResult
    {
        $activeStatuses = [];
        $blockedCount = 0;

        foreach ($input->offers as $offer) {
            $status = (string) ($offer['canonical_public_status'] ?? 'unknown');
            $valid = (bool) ($offer['valid'] ?? false);
            $blocked = (bool) ($offer['blocked'] ?? false);

            if ($blocked || ! $valid) {
                $blockedCount++;

                continue;
            }

            if (in_array($status, self::ACTIVE_STATUSES, true)) {
                $activeStatuses[] = $status;
            }
        }

        $selectedStatus = $this->selectedStatus($activeStatuses);
        $validActiveOfferCount = count($activeStatuses);
        $inactiveCount = count($input->offers) - $validActiveOfferCount - $blockedCount;

        return new CatalogOfferAggregationResult(
            productReferenceHash: $input->productReferenceHash,
            totalOfferCount: count($input->offers),
            validActiveOfferCount: $validActiveOfferCount,
            inactiveOfferCount: max(0, $inactiveCount),
            blockedOfferCount: $blockedCount,
            selectedCanonicalPublicStatus: $selectedStatus,
            hasActiveCommercialOffer: $validActiveOfferCount > 0,
            productShouldBeSellable: $validActiveOfferCount > 0,
            productVisibilityTransitionEligible: $validActiveOfferCount === 0,
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'active_commercial_statuses' => self::ACTIVE_STATUSES,
            'one_supplier_cannot_deactivate_catalog_product' => true,
            'policy_key' => self::POLICY_KEY,
            'product_write_allowed' => false,
            'supplier_offer_write_allowed' => false,
            'valid_offer_overrides_blocked_or_inactive_offer' => true,
            'write_allowed' => false,
        ];
    }

    /** @param array<int, string> $statuses */
    private function selectedStatus(array $statuses): ?string
    {
        foreach (self::STATUS_PRIORITY as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return null;
    }
}
