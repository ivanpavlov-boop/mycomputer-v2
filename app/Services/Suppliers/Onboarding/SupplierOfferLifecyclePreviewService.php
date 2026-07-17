<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogOfferAggregationInput;
use App\Data\Suppliers\Onboarding\CatalogProductDeletionPolicyInput;
use App\Data\Suppliers\Onboarding\CatalogProductVisibilityLifecycleInput;
use App\Data\Suppliers\Onboarding\SupplierOfferLifecyclePreviewReport;
use App\Data\Suppliers\Onboarding\SupplierOfferPresenceObservation;
use App\Data\Suppliers\Onboarding\SupplierOfferReappearanceInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;
use Carbon\CarbonImmutable;

final class SupplierOfferLifecyclePreviewService
{
    public function __construct(
        private readonly SupplierSnapshotQualificationPolicy $snapshotQualificationPolicy,
        private readonly SupplierOfferLifecyclePolicy $offerLifecyclePolicy,
        private readonly SupplierOfferReappearancePolicy $reappearancePolicy,
        private readonly CatalogOfferAggregationPolicy $aggregationPolicy,
        private readonly CatalogProductVisibilityLifecyclePolicy $visibilityPolicy,
        private readonly CatalogProductDeletionPolicy $deletionPolicy,
        private readonly SupplierTechnicalRetentionPolicy $retentionPolicy,
        private readonly SupplierOfferLifecycleApprovalGateFactory $approvalGateFactory,
        private readonly SupplierHumanDecisionRegistry $decisionRegistry,
        private readonly SupplierPreviewFeedProfileDesignRegistry $profileRegistry,
    ) {}

    public function preview(string $supplierKey = 'apcom', string $scenario = 'all'): SupplierOfferLifecyclePreviewReport
    {
        $supplierKey = strtolower(trim($supplierKey));
        $scenario = strtolower(trim($scenario));

        if ($supplierKey !== 'apcom' || $scenario !== 'all') {
            throw new \InvalidArgumentException('Only the bounded synthetic APCOM all-scenarios preview is available.');
        }

        $base = CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC');
        $qualified = $this->qualifiedSnapshot($supplierKey, 'synthetic-snapshot-qualified', $base);
        $failed = $this->qualification($supplierKey, 'synthetic-snapshot-failed', $base->addHour(), isSuccessful: false);
        $partial = $this->qualification($supplierKey, 'synthetic-snapshot-partial', $base->addHours(2), isFullSnapshot: false);
        $malformed = $this->qualification($supplierKey, 'synthetic-snapshot-malformed', $base->addHours(3), isSchemaValid: false);
        $truncated = $this->qualification($supplierKey, 'synthetic-snapshot-truncated', $base->addHours(3)->addMinutes(30), isTruncated: true);
        $anomalousDrop = $this->qualification($supplierKey, 'synthetic-snapshot-anomalous-drop', $base->addHours(4), productDropPercent: 51.0);
        $belowMinimum = $this->qualification($supplierKey, 'synthetic-snapshot-below-minimum', $base->addHours(5), productCount: 99);
        $duplicate = $this->qualification($supplierKey, 'synthetic-snapshot-duplicate', $base->addHours(6), isDuplicateFingerprint: true);
        $safeAfterFrozen = $this->qualifiedSnapshot($supplierKey, 'synthetic-snapshot-safe-after-frozen', $base->addHours(7));

        $firstMissingAt = $base->subHours(72);
        $firstMissing = $this->missing($supplierKey, 'synthetic-offer-a', 'present', 0, null, $firstMissingAt, $qualified);
        $secondMissing = $this->missing($supplierKey, 'synthetic-offer-a', 'missing_once', 1, $firstMissingAt, $firstMissingAt->addHour(), $qualified);
        $thirdBeforeDuration = $this->missing($supplierKey, 'synthetic-offer-a', 'missing_repeatedly', 2, $firstMissingAt, $firstMissingAt->addHours(47), $qualified);
        $thirdAtDuration = $this->missing($supplierKey, 'synthetic-offer-a', 'missing_repeatedly', 2, $firstMissingAt, $firstMissingAt->addHours(48), $qualified);
        $fourthAfterDuration = $this->missing($supplierKey, 'synthetic-offer-a', 'inactive_missing_from_feed', 3, $firstMissingAt, $firstMissingAt->addHours(72), $qualified);
        $presenceReset = $this->present($supplierKey, 'synthetic-offer-a', 'missing_repeatedly', 2, $firstMissingAt, $base, $qualified);
        $failedFreeze = $this->missing($supplierKey, 'synthetic-offer-a', 'missing_once', 1, $firstMissingAt, $base, $failed);
        $partialFreeze = $this->missing($supplierKey, 'synthetic-offer-a', 'missing_once', 1, $firstMissingAt, $base, $partial);

        $eolWithPositiveStock = (new ApcomAvailabilityMapper)->map(3, 1)->toArray();
        $validReappearance = $this->reappearance($supplierKey, 'synthetic-offer-a', 'inactive_missing_from_feed', $base, $qualified, price: 125.00);
        $zeroPriceReappearance = $this->reappearance($supplierKey, 'synthetic-offer-zero', 'inactive_missing_from_feed', $base, $qualified, price: 0.0);
        $conflictReappearance = $this->reappearance($supplierKey, 'synthetic-offer-conflict', 'inactive_missing_from_feed', $base, $qualified, price: 125.00, hasIdentifierConflict: true);

        $aggregation = [
            'apcom_inactive_asbis_in_stock' => $this->aggregation('synthetic-product-a', [
                ['canonical_public_status' => 'unavailable', 'valid' => true],
                ['canonical_public_status' => 'in_stock', 'valid' => true],
            ]),
            'apcom_missing_asbis_limited' => $this->aggregation('synthetic-product-b', [
                ['canonical_public_status' => 'unavailable', 'valid' => true],
                ['canonical_public_status' => 'limited', 'valid' => true],
            ]),
            'apcom_on_request_asbis_inactive' => $this->aggregation('synthetic-product-c', [
                ['canonical_public_status' => 'on_request', 'valid' => true],
                ['canonical_public_status' => 'unavailable', 'valid' => true],
            ]),
            'apcom_last_units_no_other_active_offer' => $this->aggregation('synthetic-product-d', [
                ['canonical_public_status' => 'last_units', 'valid' => true],
                ['canonical_public_status' => 'unavailable', 'valid' => true],
            ]),
            'all_offers_inactive' => $this->aggregation('synthetic-product-e', [
                ['canonical_public_status' => 'unavailable', 'valid' => true],
                ['canonical_public_status' => 'discontinued', 'valid' => true],
            ]),
            'one_blocked_one_valid' => $this->aggregation('synthetic-product-f', [
                ['canonical_public_status' => 'unknown', 'valid' => false, 'blocked' => true],
                ['canonical_public_status' => 'in_stock', 'valid' => true],
            ]),
            'two_active_different_statuses' => $this->aggregation('synthetic-product-g', [
                ['canonical_public_status' => 'on_request', 'valid' => true],
                ['canonical_public_status' => 'in_stock', 'valid' => true],
            ]),
        ];

        $visibility = [
            'zero_active_offers_day_0' => $this->visibility('synthetic-product-h', $base, $base, false),
            'zero_active_offers_day_59' => $this->visibility('synthetic-product-h', $base->subDays(59), $base, false),
            'zero_active_offers_day_60' => $this->visibility('synthetic-product-h', $base->subDays(60), $base, false),
            'zero_active_offers_day_61' => $this->visibility('synthetic-product-h', $base->subDays(61), $base, false),
            'zero_active_offers_month_23' => $this->visibility('synthetic-product-h', $base->subMonthsNoOverflow(23), $base, false),
            'zero_active_offers_month_24' => $this->visibility('synthetic-product-h', $base->subMonthsNoOverflow(24), $base, false),
            'valid_offer_reappears_after_archived_noindex' => $this->visibility('synthetic-product-h', $base->subDays(61), $base, true, 'in_stock'),
            'valid_offer_reappears_after_cold_archive_candidate' => $this->visibility('synthetic-product-h', $base->subMonthsNoOverflow(24), $base, true, 'in_stock'),
        ];

        $deletion = [
            'previously_published_product' => $this->deletion('synthetic-product-i', hasEverBeenPublished: true),
            'product_linked_to_order' => $this->deletion('synthetic-product-j', hasOrderHistory: true),
            'product_with_supplier_history' => $this->deletion('synthetic-product-k', hasSupplierHistory: true),
            'test_never_published_without_dependencies' => $this->deletion(
                'synthetic-product-l',
                isDemonstrablyTestDuplicateOrErroneous: true,
                seoRedirectReviewed: true,
                previewAndBackupExist: true,
            ),
        ];

        $decisionRegister = $this->decisionRegistry->apcomV3();
        $profile = $this->profileRegistry->apcomV3();

        return new SupplierOfferLifecyclePreviewReport([
            'approval_gate' => $this->approvalGateFactory->create()->toArray(),
            'catalog_offer_aggregation_policy' => $this->aggregationPolicy->policy(),
            'catalog_visibility_lifecycle_policy' => $this->visibilityPolicy->policy(),
            'deletion_policy' => $this->deletionPolicy->policy(),
            'missing_offer_policy' => $this->offerLifecyclePolicy->policy(),
            'policy_versions' => [
                'apcom_human_decisions' => $decisionRegister->key,
                'apcom_preview_feed_profile' => $profile->key,
                'apcom_semantics_profile' => $profile->semanticsProfileKey,
                'catalog_offer_aggregation' => CatalogOfferAggregationPolicy::POLICY_KEY,
                'catalog_product_deletion' => CatalogProductDeletionPolicy::POLICY_KEY,
                'catalog_product_visibility_lifecycle' => CatalogProductVisibilityLifecyclePolicy::POLICY_KEY,
                'supplier_offer_missing' => SupplierOfferLifecyclePolicy::POLICY_KEY,
                'supplier_offer_reappearance' => SupplierOfferReappearancePolicy::POLICY_KEY,
                'supplier_snapshot_qualification' => SupplierSnapshotQualificationPolicy::POLICY_KEY,
                'supplier_technical_retention' => SupplierTechnicalRetentionPolicy::POLICY_KEY,
            ],
            'reappearance_policy' => $this->reappearancePolicy->policy(),
            'records_changed' => $this->zeroRecordsChanged(),
            'safety' => [
                'catalog_sync_called' => false,
                'database_accessed' => false,
                'http_requested' => false,
                'image_action_performed' => false,
                'operational_execution_allowed' => false,
                'real_supplier_data_used' => false,
                'schedule_changed' => false,
                'synthetic_data_only' => true,
            ],
            'snapshot_qualification_policy' => $this->snapshotQualificationPolicy->policy(),
            'supplier' => $supplierKey,
            'synthetic_scenarios' => [
                'deletion' => $deletion,
                'multi_supplier_aggregation' => $aggregation,
                'offer_lifecycle' => [
                    'product_present' => $this->present($supplierKey, 'synthetic-offer-a', 'present', 0, null, $base, $qualified),
                    'first_missing_observation' => $firstMissing,
                    'second_consecutive_missing_observation' => $secondMissing,
                    'third_missing_before_48_hours' => $thirdBeforeDuration,
                    'third_missing_at_48_hours' => $thirdAtDuration,
                    'fourth_missing_after_48_hours' => $fourthAfterDuration,
                    'missing_sequence_interrupted_by_presence' => $presenceReset,
                    'missing_sequence_interrupted_by_failed_snapshot' => $failedFreeze,
                    'missing_sequence_interrupted_by_partial_snapshot' => $partialFreeze,
                    'valid_reappearance' => $validReappearance,
                    'zero_price_reappearance' => $zeroPriceReappearance,
                    'identifier_conflict_reappearance' => $conflictReappearance,
                    'eol_product_still_present_positive_stock' => [
                        'canonical_public_status' => $eolWithPositiveStock['canonical_public_status'],
                        'source_absence_is_eol' => false,
                        'write_allowed' => false,
                        'records_changed' => 0,
                    ],
                    'product_absent_from_source_not_eol' => $firstMissing,
                ],
                'snapshot_qualification' => [
                    'successful_full_snapshot' => $qualified,
                    'failed_snapshot' => $failed,
                    'partial_snapshot' => $partial,
                    'malformed_snapshot' => $malformed,
                    'truncated_snapshot' => $truncated,
                    'anomalous_product_count_drop' => $anomalousDrop,
                    'below_minimum_product_count' => $belowMinimum,
                    'duplicate_snapshot_fingerprint' => $duplicate,
                    'safe_snapshot_after_frozen_snapshot' => $safeAfterFrozen,
                ],
                'visibility_lifecycle' => $visibility,
            ],
            'technical_retention_policy' => $this->retentionPolicy->policy(),
        ]);
    }

    private function qualifiedSnapshot(string $supplierKey, string $snapshotId, CarbonImmutable $observedAt): SupplierSnapshotQualificationResult
    {
        return $this->qualification($supplierKey, $snapshotId, $observedAt);
    }

    private function qualification(
        string $supplierKey,
        string $snapshotId,
        CarbonImmutable $observedAt,
        bool $isSuccessful = true,
        bool $isFullSnapshot = true,
        bool $isSchemaValid = true,
        bool $isTruncated = false,
        int $productCount = 100,
        float $productDropPercent = 5.0,
        bool $isDuplicateFingerprint = false,
    ): SupplierSnapshotQualificationResult {
        return $this->snapshotQualificationPolicy->qualify(new SupplierSnapshotQualificationInput(
            supplierKey: $supplierKey,
            snapshotId: $snapshotId,
            snapshotStatus: $isSuccessful ? 'completed' : 'failed',
            observedAt: $observedAt,
            isSuccessful: $isSuccessful,
            isFullSnapshot: $isFullSnapshot,
            isSchemaValid: $isSchemaValid,
            isTruncated: $isTruncated,
            productCount: $productCount,
            minimumProductCount: 100,
            productDropPercent: $productDropPercent,
            maximumProductDropPercent: 50.0,
            hasFatalBlocker: false,
            supplierIdentityConfirmed: true,
            snapshotFingerprint: 'synthetic-fingerprint-'.hash('sha256', $snapshotId),
            isDuplicateFingerprint: $isDuplicateFingerprint,
        ));
    }

    private function missing(string $supplierKey, string $supplierSkuHash, string $previousStatus, int $previousCount, ?CarbonImmutable $firstMissingAt, CarbonImmutable $evaluatedAt, SupplierSnapshotQualificationResult $qualification): array
    {
        return $this->offerLifecyclePolicy->preview(new SupplierOfferPresenceObservation(
            supplierKey: $supplierKey,
            supplierSkuHash: $supplierSkuHash,
            previousPresenceStatus: $previousStatus,
            previousConsecutiveMissingCount: $previousCount,
            previousFirstMissingAt: $firstMissingAt,
            evaluatedAt: $evaluatedAt,
            isPresentInSnapshot: false,
        ), $qualification)->toArray();
    }

    private function present(string $supplierKey, string $supplierSkuHash, string $previousStatus, int $previousCount, ?CarbonImmutable $firstMissingAt, CarbonImmutable $evaluatedAt, SupplierSnapshotQualificationResult $qualification): array
    {
        return $this->offerLifecyclePolicy->preview(new SupplierOfferPresenceObservation(
            supplierKey: $supplierKey,
            supplierSkuHash: $supplierSkuHash,
            previousPresenceStatus: $previousStatus,
            previousConsecutiveMissingCount: $previousCount,
            previousFirstMissingAt: $firstMissingAt,
            evaluatedAt: $evaluatedAt,
            isPresentInSnapshot: true,
        ), $qualification)->toArray();
    }

    private function reappearance(string $supplierKey, string $supplierSkuHash, string $previousStatus, CarbonImmutable $evaluatedAt, SupplierSnapshotQualificationResult $qualification, float $price, bool $hasIdentifierConflict = false): array
    {
        return $this->reappearancePolicy->preview(new SupplierOfferReappearanceInput(
            supplierKey: $supplierKey,
            supplierSkuHash: $supplierSkuHash,
            previousPresenceStatus: $previousStatus,
            evaluatedAt: $evaluatedAt,
            supplierSkuMatchesExactly: true,
            price: $price,
            supplierMapperValid: true,
            hasIdentifierConflict: $hasIdentifierConflict,
            hasBlockingValidationIssue: false,
        ), $qualification)->toArray();
    }

    /** @param array<int, array{canonical_public_status: string, valid: bool, blocked?: bool}> $offers */
    private function aggregation(string $productReferenceHash, array $offers): array
    {
        return $this->aggregationPolicy->preview(new CatalogOfferAggregationInput($productReferenceHash, $offers))->toArray();
    }

    private function visibility(string $productReferenceHash, CarbonImmutable $zeroActiveOffersSince, CarbonImmutable $evaluatedAt, bool $hasActiveCommercialOffer, ?string $status = null): array
    {
        return $this->visibilityPolicy->preview(new CatalogProductVisibilityLifecycleInput(
            productReferenceHash: $productReferenceHash,
            zeroActiveOffersSince: $zeroActiveOffersSince,
            evaluatedAt: $evaluatedAt,
            hasActiveCommercialOffer: $hasActiveCommercialOffer,
            canonicalPublicStatus: $status,
        ))->toArray();
    }

    private function deletion(
        string $productReferenceHash,
        bool $hasEverBeenPublished = false,
        bool $hasOrderHistory = false,
        bool $hasSupplierHistory = false,
        bool $isDemonstrablyTestDuplicateOrErroneous = false,
        bool $seoRedirectReviewed = false,
        bool $previewAndBackupExist = false,
    ): array {
        return $this->deletionPolicy->preview(new CatalogProductDeletionPolicyInput(
            productReferenceHash: $productReferenceHash,
            hasEverBeenPublished: $hasEverBeenPublished,
            hasOrderHistory: $hasOrderHistory,
            hasSupplierHistory: $hasSupplierHistory,
            hasActiveSupplierOffer: false,
            hasRequiredRelationalDependency: false,
            isDemonstrablyTestDuplicateOrErroneous: $isDemonstrablyTestDuplicateOrErroneous,
            hasExplicitDuplicateConsolidationPlan: false,
            seoRedirectReviewed: $seoRedirectReviewed,
            previewAndBackupExist: $previewAndBackupExist,
        ))->toArray();
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        return [
            'catalog_sync' => 0,
            'catalog_sync_batches' => 0,
            'catalog_sync_logs' => 0,
            'products' => 0,
            'product_supplier_offers' => 0,
            'supplier_import_runs' => 0,
            'supplier_products' => 0,
            'suppliers' => 0,
        ];
    }
}
