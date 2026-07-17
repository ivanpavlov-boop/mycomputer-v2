<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferLifecyclePreviewResult;
use App\Data\Suppliers\Onboarding\SupplierOfferPresenceObservation;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;
use Carbon\CarbonImmutable;

final class SupplierOfferLifecyclePolicy
{
    public const POLICY_KEY = 'supplier-offer-missing-policy-v1';

    public const MISSING_SNAPSHOT_THRESHOLD = 3;

    public const MINIMUM_MISSING_DURATION_HOURS = 48;

    public function preview(
        SupplierOfferPresenceObservation $observation,
        SupplierSnapshotQualificationResult $qualification,
    ): SupplierOfferLifecyclePreviewResult {
        $previousFirstMissingAt = $observation->previousFirstMissingAt;
        $previousDurationHours = $this->durationHours($previousFirstMissingAt, $observation->evaluatedAt);

        if (! $qualification->qualifiesForPresenceTracking) {
            return $this->result(
                observation: $observation,
                nextPresenceStatus: $observation->previousPresenceStatus,
                consecutiveMissingCount: $observation->previousConsecutiveMissingCount,
                firstMissingAt: $previousFirstMissingAt,
                durationHours: $previousDurationHours,
                requiresAvailabilityConfirmation: $observation->previousConsecutiveMissingCount > 0,
                requiresHumanReview: true,
                freezeReasonCodes: $qualification->freezeReasonCodes,
            );
        }

        if ($observation->isPresentInSnapshot) {
            return $this->result(
                observation: $observation,
                nextPresenceStatus: 'present',
                consecutiveMissingCount: 0,
                firstMissingAt: null,
                durationHours: 0,
                requiresAvailabilityConfirmation: false,
                requiresHumanReview: false,
            );
        }

        $count = $observation->previousConsecutiveMissingCount + 1;
        $firstMissingAt = $previousFirstMissingAt ?? $observation->evaluatedAt;
        $durationHours = $this->durationHours($firstMissingAt, $observation->evaluatedAt);
        $eligible = $count >= self::MISSING_SNAPSHOT_THRESHOLD && $durationHours >= self::MINIMUM_MISSING_DURATION_HOURS;

        $status = match (true) {
            $eligible => 'inactive_missing_from_feed',
            $count >= self::MISSING_SNAPSHOT_THRESHOLD => 'missing_threshold_reached_waiting_duration',
            $count === 2 => 'missing_repeatedly',
            default => 'missing_once',
        };

        return $this->result(
            observation: $observation,
            nextPresenceStatus: $status,
            consecutiveMissingCount: $count,
            firstMissingAt: $firstMissingAt,
            durationHours: $durationHours,
            deactivationEligible: $eligible,
            requiresAvailabilityConfirmation: $count < self::MISSING_SNAPSHOT_THRESHOLD,
            requiresHumanReview: $eligible,
        );
    }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'absence_is_eol' => false,
            'automatic_catalog_product_delete_allowed' => false,
            'automatic_catalog_product_unpublish_allowed' => false,
            'automatic_supplier_link_change_allowed' => false,
            'automatic_supplier_offer_write_allowed' => false,
            'consecutive_qualified_missing_snapshots' => self::MISSING_SNAPSHOT_THRESHOLD,
            'minimum_elapsed_duration_hours' => self::MINIMUM_MISSING_DURATION_HOURS,
            'policy_key' => self::POLICY_KEY,
            'supplier_offer_only' => true,
            'write_allowed' => false,
        ];
    }

    private function result(
        SupplierOfferPresenceObservation $observation,
        string $nextPresenceStatus,
        int $consecutiveMissingCount,
        ?CarbonImmutable $firstMissingAt,
        int $durationHours,
        bool $deactivationEligible = false,
        bool $requiresAvailabilityConfirmation = false,
        bool $requiresHumanReview = false,
        array $freezeReasonCodes = [],
    ): SupplierOfferLifecyclePreviewResult {
        return new SupplierOfferLifecyclePreviewResult(
            supplierKey: $observation->supplierKey,
            supplierSkuHash: $observation->supplierSkuHash,
            previousPresenceStatus: $observation->previousPresenceStatus,
            nextPresenceStatus: $nextPresenceStatus,
            consecutiveMissingCount: $consecutiveMissingCount,
            firstMissingAt: $firstMissingAt,
            evaluatedAt: $observation->evaluatedAt,
            missingDurationHours: $durationHours,
            missingSnapshotThreshold: self::MISSING_SNAPSHOT_THRESHOLD,
            minimumMissingDurationHours: self::MINIMUM_MISSING_DURATION_HOURS,
            deactivationEligible: $deactivationEligible,
            reactivationEligible: false,
            wouldDeactivateSupplierOffer: $deactivationEligible,
            wouldReactivateSupplierOffer: false,
            requiresAvailabilityConfirmation: $requiresAvailabilityConfirmation,
            requiresHumanReview: $requiresHumanReview,
            freezeReasonCodes: $freezeReasonCodes,
        );
    }

    private function durationHours(?CarbonImmutable $firstMissingAt, CarbonImmutable $evaluatedAt): int
    {
        if ($firstMissingAt === null) {
            return 0;
        }

        return max(0, intdiv($firstMissingAt->diffInSeconds($evaluatedAt, false), 3600));
    }
}
