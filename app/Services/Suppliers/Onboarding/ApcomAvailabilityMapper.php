<?php

namespace App\Services\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\SupplierAvailabilityMapper;
use App\Data\Suppliers\Onboarding\SupplierAvailabilityEvidence;
use App\Data\Suppliers\Onboarding\SupplierAvailabilityMappingResult;
use App\Enums\Suppliers\Onboarding\CanonicalPublicAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierLifecycleStatus;
use InvalidArgumentException;

final class ApcomAvailabilityMapper implements SupplierAvailabilityMapper
{
    public const POLICY_KEY = 'apcom-availability-policy-v1';

    public const LOW_STOCK_THRESHOLD = 5;

    public const QUANTITY_CAP = 100;

    public function supplierKey(): string
    {
        return 'apcom';
    }

    public function map(int|string|float $rawQuantityObserved, int|string|float $eolFlag): SupplierAvailabilityMappingResult
    {
        $rawQuantityObserved = $this->integer($rawQuantityObserved, 'stock');
        $eolFlag = $this->integer($eolFlag, 'EOL');

        if ($rawQuantityObserved < 0 || $rawQuantityObserved > self::QUANTITY_CAP) {
            throw new InvalidArgumentException('APCOM stock must be an integer from 0 through 100.');
        }
        if (! in_array($eolFlag, [0, 1], true)) {
            throw new InvalidArgumentException('APCOM EOL must be 0 or 1.');
        }

        $lifecycle = $eolFlag === 1
            ? CanonicalSupplierLifecycleStatus::Eol
            : CanonicalSupplierLifecycleStatus::Active;
        $availability = $this->availability($rawQuantityObserved, $lifecycle);
        $public = $this->publicStatus($rawQuantityObserved, $lifecycle, $availability);
        $capped = $rawQuantityObserved === self::QUANTITY_CAP;

        return new SupplierAvailabilityMappingResult(
            supplierKey: $this->supplierKey(),
            policyKey: self::POLICY_KEY,
            canonicalAvailabilityStatus: $availability,
            canonicalLifecycleStatus: $lifecycle,
            canonicalPublicStatus: $public,
            rawQuantityObserved: $rawQuantityObserved,
            quantityIsCapped: $capped,
            quantityMinimum: $capped ? self::QUANTITY_CAP : null,
            exactPublicQuantityAllowed: false,
            lowStockThreshold: self::LOW_STOCK_THRESHOLD,
            requiresHumanReview: $rawQuantityObserved === 0 || $eolFlag === 1,
            requiresAvailabilityConfirmation: $rawQuantityObserved === 0 && $eolFlag === 0,
            orderableInPrinciple: $public !== CanonicalPublicAvailabilityStatus::Discontinued,
            evidence: $this->evidence($rawQuantityObserved, $eolFlag),
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'automatic_catalog_quantity_write_allowed' => false,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'exact_public_quantity_allowed' => false,
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'policy_key' => self::POLICY_KEY,
            'quantity_cap' => self::QUANTITY_CAP,
            'quantity_cap_meaning' => '100_or_more',
            'raw_quantity_role' => 'supplier_available_quantity_snapshot',
            'snapshot_is_realtime_guarantee' => false,
            'staging_write_allowed' => false,
        ];
    }

    private function availability(int $quantity, CanonicalSupplierLifecycleStatus $lifecycle): CanonicalSupplierAvailabilityStatus
    {
        if ($quantity === 0) {
            return $lifecycle === CanonicalSupplierLifecycleStatus::Eol
                ? CanonicalSupplierAvailabilityStatus::OutOfStock
                : CanonicalSupplierAvailabilityStatus::OnRequest;
        }

        return $quantity <= self::LOW_STOCK_THRESHOLD
            ? CanonicalSupplierAvailabilityStatus::Limited
            : CanonicalSupplierAvailabilityStatus::InStock;
    }

    private function integer(int|string|float $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new InvalidArgumentException("APCOM {$field} must be a non-negative integer.");
    }

    private function publicStatus(
        int $quantity,
        CanonicalSupplierLifecycleStatus $lifecycle,
        CanonicalSupplierAvailabilityStatus $availability,
    ): CanonicalPublicAvailabilityStatus {
        if ($lifecycle === CanonicalSupplierLifecycleStatus::Eol) {
            return $quantity > 0
                ? CanonicalPublicAvailabilityStatus::LastUnits
                : CanonicalPublicAvailabilityStatus::Discontinued;
        }

        return match ($availability) {
            CanonicalSupplierAvailabilityStatus::InStock => CanonicalPublicAvailabilityStatus::InStock,
            CanonicalSupplierAvailabilityStatus::Limited => CanonicalPublicAvailabilityStatus::Limited,
            CanonicalSupplierAvailabilityStatus::OnRequest => CanonicalPublicAvailabilityStatus::OnRequest,
            CanonicalSupplierAvailabilityStatus::OutOfStock => CanonicalPublicAvailabilityStatus::Unavailable,
            default => CanonicalPublicAvailabilityStatus::Unknown,
        };
    }

    /** @return array<int, SupplierAvailabilityEvidence> */
    private function evidence(int $quantity, int $eolFlag): array
    {
        $codes = [
            'operator_portal_crosscheck_stock_exact_quantity',
            'operator_approved_public_availability_policy',
        ];

        if ($quantity === 0) {
            $codes[] = 'operator_portal_crosscheck_stock_zero_on_request';
        }
        if ($quantity === self::QUANTITY_CAP) {
            $codes[] = 'operator_portal_crosscheck_stock_cap_100_plus';
        }
        if ($eolFlag === 1 && $quantity > 0) {
            $codes[] = 'operator_portal_crosscheck_eol_positive_stock_orderable';
        }

        return array_map(
            static fn (string $code): SupplierAvailabilityEvidence => new SupplierAvailabilityEvidence(
                code: $code,
                type: 'operator_confirmed_business_evidence',
                description: 'Operator-confirmed business evidence; not official supplier documentation.',
            ),
            $codes,
        );
    }
}
