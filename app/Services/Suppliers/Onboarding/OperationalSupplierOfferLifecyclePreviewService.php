<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CanonicalOnboardingData;
use App\Data\Suppliers\Onboarding\CatalogOfferAggregationInput;
use App\Data\Suppliers\Onboarding\CatalogProductDeletionPolicyInput;
use App\Data\Suppliers\Onboarding\CatalogProductVisibilityLifecycleInput;
use App\Data\Suppliers\Onboarding\DecimalNormalizer;
use App\Data\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundle;
use App\Data\Suppliers\Onboarding\OperationalSupplierOfferLifecyclePreviewReport;
use App\Data\Suppliers\Onboarding\SupplierOfferPresenceObservation;
use App\Data\Suppliers\Onboarding\SupplierOfferReappearanceInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;
use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OperationalSupplierOfferLifecyclePreviewService
{
    /** @var array<int, string> */
    private const PROTECTED_TABLES = [
        'suppliers',
        'supplier_products',
        'product_supplier_offers',
        'products',
        'categories',
        'supplier_category_mappings',
        'category_product_attributes',
        'product_attributes',
        'attribute_values',
        'product_attribute_values',
        'brands',
        'product_images',
        'media',
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'supplier_import_runs',
        'import_jobs',
        'import_histories',
        'catalog_sync_batches',
        'catalog_sync_logs',
        'jobs',
        'failed_jobs',
    ];

    /** @var array<int, string> */
    private const PUBLIC_WORKFLOW_STATES = [Product::WORKFLOW_PUBLISHED];

    public function __construct(
        private readonly SupplierImportActivityInspector $importActivityInspector,
        private readonly SupplierSnapshotQualificationPolicy $snapshotQualificationPolicy,
        private readonly SupplierOfferLifecyclePolicy $offerLifecyclePolicy,
        private readonly SupplierOfferReappearancePolicy $offerReappearancePolicy,
        private readonly CatalogOfferAggregationPolicy $offerAggregationPolicy,
        private readonly CatalogProductVisibilityLifecyclePolicy $visibilityLifecyclePolicy,
        private readonly CatalogProductDeletionPolicy $deletionPolicy,
        private readonly SupplierTechnicalRetentionPolicy $retentionPolicy,
        private readonly ApcomAvailabilityMapper $apcomAvailabilityMapper,
        private readonly SupplierOfferLifecycleEvaluationGateFactory $evaluationGateFactory,
        private readonly OperationalSupplierOfferIdentityHasher $hasher,
    ) {}

    public function preview(
        OperationalSupplierOfferEvidenceBundle $bundle,
        CarbonImmutable $evaluatedAt,
        int $sampleLimit,
    ): OperationalSupplierOfferLifecyclePreviewReport {
        if ($bundle->supplierKey !== 'apcom') {
            throw new InvalidArgumentException('supplier_scope_mismatch');
        }
        if ($sampleLimit < 1 || $sampleLimit > 100) {
            throw new InvalidArgumentException('invalid_sample_limit');
        }

        $flags = $this->catalogSyncFlags();
        if ($flags !== [
            'catalog_sync_auto_enabled' => false,
            'catalog_sync_create_enabled' => true,
            'catalog_sync_sync_all_enabled' => false,
            'catalog_sync_update_enabled' => false,
        ]) {
            throw new InvalidArgumentException('unsafe_catalog_sync_configuration');
        }

        $suppliers = $this->suppliers($bundle->supplierScope);
        $this->assertSupplierAndImportSafety($suppliers, $bundle);
        $importGeneration = $this->importGeneration($suppliers);
        $beforeState = $this->protectedStateSignature();
        $catalog = $this->catalogState($suppliers);

        [$qualifications, $offerStates, $latestObservations, $reappearanceHashes] = $this->evaluateHistory(
            $bundle,
            $suppliers,
            $evaluatedAt,
        );
        $this->assertCatalogEvidenceCoverage($catalog, $latestObservations);
        $offerRows = $this->offerRows(
            $bundle,
            $catalog,
            $offerStates,
            $latestObservations,
            $reappearanceHashes,
            $evaluatedAt,
        );
        $productRows = $this->productRows($bundle, $catalog, $offerRows, $evaluatedAt);

        $reasonCounts = $this->reasonCounts($qualifications, $offerRows, $productRows);
        $recommendationCounts = $this->recommendationCounts($offerRows, $productRows);
        $counts = $this->counts($qualifications, $offerRows, $productRows, $reappearanceHashes);
        $humanReviewRequired = ($recommendationCounts['manual_review'] ?? 0) > 0 || $reasonCounts !== [];

        $recordsChanged = array_fill_keys(self::PROTECTED_TABLES, 0);
        ksort($recordsChanged);

        $report = new OperationalSupplierOfferLifecyclePreviewReport([
            'bounded_samples' => [
                'limit' => $sampleLimit,
                'offer_evaluations' => array_slice(array_map($this->publicOfferRow(...), $offerRows), 0, $sampleLimit),
                'product_recommendations' => array_slice($productRows, 0, $sampleLimit),
                'sample_hash_namespace' => OperationalSupplierOfferIdentityHasher::HASH_NAMESPACE,
            ],
            'catalog_state_fingerprint' => $catalog['fingerprint'],
            'catalog_sync_flags' => $flags,
            'counts' => $counts,
            'decision_authority' => 'apcom-missing-offer-decisions-v4',
            'dispatched_events' => 0,
            'dispatched_jobs' => 0,
            'evaluated_at' => $evaluatedAt->toAtomString(),
            'evaluation_gate' => $this->evaluationGateFactory->create()->toArray(),
            'evidence_bundle_fingerprint' => $bundle->evidenceFingerprint,
            'evidence_schema_version' => OperationalSupplierOfferEvidenceBundle::SCHEMA_VERSION,
            'human_review_required' => $humanReviewRequired,
            'mode' => 'operational_input_driven_preview',
            'ordered_snapshot_fingerprints' => array_values(array_map(
                static fn (array $snapshot): string => $snapshot['fingerprint'],
                $bundle->snapshots,
            )),
            'persisted' => false,
            'policy_versions' => $bundle->policyVersions,
            'protected_state_unchanged' => true,
            'reason_code_counts' => $reasonCounts,
            'recommendation_counts' => $recommendationCounts,
            'records_changed' => $recordsChanged,
            'retention_plan' => $this->retentionPolicy->policy(),
            'supplier' => $bundle->supplierKey,
            'supplier_scope' => $bundle->supplierScope,
            'write_allowed' => false,
        ]);

        $finalSuppliers = $this->suppliers($bundle->supplierScope);
        $this->assertSupplierAndImportSafety($finalSuppliers, $bundle);
        if ($this->importGeneration($finalSuppliers) !== $importGeneration) {
            throw new RuntimeException('import_generation_changed_during_preview');
        }
        $finalCatalog = $this->catalogState($finalSuppliers);
        $finalState = $this->protectedStateSignature();
        if (! hash_equals($beforeState, $finalState) || ! hash_equals($catalog['fingerprint'], $finalCatalog['fingerprint'])) {
            throw new RuntimeException('protected_state_changed_during_preview');
        }

        return $report;
    }

    /** @return array<string, bool> */
    private function catalogSyncFlags(): array
    {
        $flags = [
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
        ];
        ksort($flags);

        return $flags;
    }

    /** @param array<int, string> $scope @return Collection<string, Supplier> */
    private function suppliers(array $scope): Collection
    {
        $suppliers = Supplier::query()
            ->whereIn('slug', $scope)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Supplier $supplier): string => strtolower($supplier->slug));
        if ($suppliers->count() !== count($scope)) {
            throw new InvalidArgumentException('supplier_scope_not_found');
        }

        return $suppliers;
    }

    /** @param Collection<string, Supplier> $suppliers */
    private function assertSupplierAndImportSafety(Collection $suppliers, OperationalSupplierOfferEvidenceBundle $bundle): void
    {
        foreach ($suppliers as $key => $supplier) {
            if (strtolower((string) $supplier->status) !== 'active') {
                throw new InvalidArgumentException('disabled_or_deleted_record');
            }
            if ($key === 'apcom' && (bool) $supplier->schedule_enabled) {
                throw new InvalidArgumentException('supplier_schedule_must_remain_disabled');
            }
            $activity = $this->importActivityInspector->inspect((int) $supplier->id);
            if ($activity['state'] === 'active') {
                throw new InvalidArgumentException('active_import_state');
            }
            if ($activity['state'] !== 'clear') {
                throw new InvalidArgumentException('unknown_import_state');
            }
        }

        foreach ($bundle->snapshots as $snapshot) {
            /** @var Supplier $supplier */
            $supplier = $suppliers->get($snapshot['supplier']);
            if ((int) $snapshot['minimum_product_count'] !== (int) $supplier->minimum_product_count
                || $snapshot['maximum_product_drop_percent'] !== (string) (int) $supplier->maximum_product_drop_percent) {
                throw new InvalidArgumentException('supplier_snapshot_baseline_mismatch');
            }
        }
    }

    /** @param Collection<string, Supplier> $suppliers @return array<int, int> */
    private function importGeneration(Collection $suppliers): array
    {
        foreach (['id', 'supplier_id'] as $column) {
            if (! Schema::hasTable('import_histories') || ! Schema::hasColumn('import_histories', $column)) {
                throw new RuntimeException('import_generation_unavailable');
            }
        }

        $supplierIds = $suppliers
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort(SORT_NUMERIC)
            ->values();
        $maximumIds = DB::table('import_histories')
            ->whereIn('supplier_id', $supplierIds->all())
            ->selectRaw('supplier_id, MAX(id) AS maximum_id')
            ->groupBy('supplier_id')
            ->orderBy('supplier_id')
            ->pluck('maximum_id', 'supplier_id');

        return $supplierIds->mapWithKeys(static fn (int $supplierId): array => [
            $supplierId => (int) ($maximumIds[$supplierId] ?? 0),
        ])->all();
    }

    /** @param Collection<string, Supplier> $suppliers @return array<string, mixed> */
    private function catalogState(Collection $suppliers): array
    {
        $orderedSuppliers = $suppliers
            ->sortBy(static fn (Supplier $supplier): int => (int) $supplier->id, SORT_NUMERIC)
            ->values();
        $supplierIds = $orderedSuppliers->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $offers = ProductSupplierOffer::query()->whereIn('supplier_id', $supplierIds)->orderBy('id')->get();
        $staged = SupplierProduct::query()->whereIn('supplier_id', $supplierIds)->orderBy('id')->get();
        $productIds = $offers->pluck('product_id')->merge($staged->pluck('product_id'))->filter()->unique()->values()->all();
        $products = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->get()->keyBy('id');
        $allOfferCounts = ProductSupplierOffer::query()
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, COUNT(*) AS aggregate')
            ->groupBy('product_id')
            ->pluck('aggregate', 'product_id')
            ->map(static fn (mixed $count): int => (int) $count);
        $supplierKeys = $orderedSuppliers->mapWithKeys(
            static fn (Supplier $supplier): array => [(int) $supplier->id => strtolower((string) $supplier->slug)],
        );

        $offerIndex = [];
        foreach ($offers as $offer) {
            $supplierKey = $supplierKeys[(int) $offer->supplier_id];
            $hash = $this->hasher->supplierSku($supplierKey, (string) $offer->supplier_sku);
            $offerIndex[$supplierKey.'|'.$hash][] = $offer;
        }
        $stagedIndex = [];
        foreach ($staged as $row) {
            $supplierKey = $supplierKeys[(int) $row->supplier_id];
            $hash = $this->hasher->supplierSku($supplierKey, (string) $row->supplier_sku);
            $stagedIndex[$supplierKey.'|'.$hash][] = $row;
        }

        $fingerprintPayload = [
            'offers' => $offers->map(fn (ProductSupplierOffer $offer): array => [
                'currency' => $offer->currency,
                'id' => $offer->id,
                'price' => $offer->price,
                'product_id' => $offer->product_id,
                'quantity' => $offer->quantity,
                'supplier_id' => $offer->supplier_id,
                'supplier_product_id' => $offer->supplier_product_id,
                'supplier_sku_hash' => $this->hasher->supplierSku($supplierKeys[(int) $offer->supplier_id], (string) $offer->supplier_sku),
                'updated_at' => $offer->updated_at?->toAtomString(),
            ])->all(),
            'products' => $products->map(static fn (Product $product): array => [
                'active' => (bool) $product->active,
                'deleted_at' => $product->deleted_at?->toAtomString(),
                'id' => $product->id,
                'manual_override' => (bool) $product->manual_override,
                'product_status' => $product->product_status,
                'published_at' => $product->published_at?->toAtomString(),
                'source' => $product->source,
                'updated_at' => $product->updated_at?->toAtomString(),
                'workflow_status' => $product->workflow_status,
            ])->values()->all(),
            'staged' => $staged->map(fn (SupplierProduct $row): array => [
                'availability_status_id' => $row->availability_status_id,
                'id' => $row->id,
                'price' => $row->price,
                'product_id' => $row->product_id,
                'quantity' => $row->quantity,
                'status' => $row->status,
                'supplier_id' => $row->supplier_id,
                'supplier_sku_hash' => $this->hasher->supplierSku($supplierKeys[(int) $row->supplier_id], (string) $row->supplier_sku),
                'updated_at' => $row->updated_at?->toAtomString(),
            ])->all(),
            'suppliers' => $orderedSuppliers->map(static fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'import_enabled' => (bool) $supplier->import_enabled,
                'maximum_product_drop_percent' => (int) $supplier->maximum_product_drop_percent,
                'minimum_product_count' => (int) $supplier->minimum_product_count,
                'schedule_enabled' => (bool) $supplier->schedule_enabled,
                'slug' => $supplier->slug,
                'status' => $supplier->status,
                'updated_at' => $supplier->updated_at?->toAtomString(),
            ])->values()->all(),
        ];

        return [
            'fingerprint' => hash('sha256', CanonicalOnboardingData::encode($fingerprintPayload)),
            'all_offer_counts' => $allOfferCounts,
            'offer_index' => $offerIndex,
            'offers' => $offers,
            'products' => $products,
            'staged_index' => $stagedIndex,
        ];
    }

    /**
     * @param  Collection<string, Supplier>  $suppliers
     * @return array{0: array<int, SupplierSnapshotQualificationResult>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>, 3: array<string, bool>}
     */
    private function evaluateHistory(OperationalSupplierOfferEvidenceBundle $bundle, Collection $suppliers, CarbonImmutable $evaluatedAt): array
    {
        $qualifications = [];
        $states = [];
        $latest = [];
        $seenFingerprints = [];
        $reappearances = [];

        foreach ($bundle->snapshots as $snapshot) {
            $capturedAt = CarbonImmutable::parse($snapshot['captured_at']);
            if ($capturedAt->greaterThan($evaluatedAt)) {
                throw new InvalidArgumentException('snapshot_after_evaluated_at');
            }
            $supplierKey = $snapshot['supplier'];
            /** @var Supplier $supplier */
            $supplier = $suppliers->get($supplierKey);
            $duplicateFingerprint = isset($seenFingerprints[$supplierKey][$snapshot['fingerprint']]);
            $seenFingerprints[$supplierKey][$snapshot['fingerprint']] = true;
            $qualification = $this->snapshotQualificationPolicy->qualify(new SupplierSnapshotQualificationInput(
                supplierKey: $supplierKey,
                snapshotId: $snapshot['snapshot_id'],
                snapshotStatus: $snapshot['status'],
                observedAt: $capturedAt,
                isSuccessful: $snapshot['successful'],
                isFullSnapshot: $snapshot['full'],
                isSchemaValid: $snapshot['schema_valid'],
                isTruncated: $snapshot['truncated'],
                productCount: $snapshot['product_count'],
                minimumProductCount: (int) $supplier->minimum_product_count,
                productDropPercent: $snapshot['product_drop_percent'],
                maximumProductDropPercent: (string) (int) $supplier->maximum_product_drop_percent,
                hasFatalBlocker: $snapshot['fatal_integrity_blocker'],
                supplierIdentityConfirmed: $snapshot['supplier_identity_confirmed'],
                snapshotFingerprint: $snapshot['fingerprint'],
                isDuplicateFingerprint: $duplicateFingerprint,
            ));
            if (! $snapshot['comparable']) {
                $qualification = $this->qualificationWithReason($qualification, 'snapshot_not_comparable');
            }
            $qualifications[] = $qualification;

            foreach ($snapshot['observations'] as $observation) {
                $stateKey = $supplierKey.'|'.$observation['supplier_sku_hash'];
                unset($reappearances[$stateKey]);
                $state = $states[$stateKey] ?? [
                    'confirmed_missing_at' => null,
                    'consecutive_missing_count' => 0,
                    'first_missing_at' => null,
                    'presence_status' => 'present',
                ];

                $validPresenceIdentity = $observation['present']
                    && $qualification->qualifiesForPresenceTracking
                    && $observation['exact_supplier_sku_match']
                    && $observation['supplier_mapper_valid']
                    && ! $observation['identifier_conflict']
                    && ! $observation['blocking_validation_issue']
                    && ! $observation['duplicate_offer'];

                if ($supplierKey !== 'apcom') {
                    if ($qualification->qualifiesForPresenceTracking && (! $observation['present'] || $validPresenceIdentity)) {
                        $state = [
                            'confirmed_missing_at' => null,
                            'consecutive_missing_count' => 0,
                            'first_missing_at' => null,
                            'presence_status' => $observation['present'] ? 'present' : 'unreviewed_absence',
                        ];
                    }
                } elseif ($observation['present'] && $state['presence_status'] === 'inactive_missing_from_feed') {
                    $reappearance = $this->offerReappearancePolicy->preview(new SupplierOfferReappearanceInput(
                        supplierKey: $supplierKey,
                        supplierSkuHash: $observation['supplier_sku_hash'],
                        previousPresenceStatus: $state['presence_status'],
                        evaluatedAt: $capturedAt,
                        supplierSkuMatchesExactly: $observation['exact_supplier_sku_match'],
                        price: $observation['price'],
                        supplierMapperValid: $observation['supplier_mapper_valid'],
                        hasIdentifierConflict: $observation['identifier_conflict'],
                        hasBlockingValidationIssue: $observation['blocking_validation_issue'],
                    ), $qualification);
                    if ($validPresenceIdentity) {
                        $state = [
                            'confirmed_missing_at' => null,
                            'consecutive_missing_count' => 0,
                            'first_missing_at' => null,
                            'presence_status' => 'present',
                        ];
                        if ($reappearance->reactivationEligible) {
                            $reappearances[$stateKey] = true;
                        }
                    }
                } elseif (! $observation['present'] || $validPresenceIdentity) {
                    $result = $this->offerLifecyclePolicy->preview(new SupplierOfferPresenceObservation(
                        supplierKey: $supplierKey,
                        supplierSkuHash: $observation['supplier_sku_hash'],
                        previousPresenceStatus: $state['presence_status'],
                        previousConsecutiveMissingCount: $state['consecutive_missing_count'],
                        previousFirstMissingAt: $state['first_missing_at'],
                        evaluatedAt: $capturedAt,
                        isPresentInSnapshot: $observation['present'],
                    ), $qualification);
                    $state = [
                        'confirmed_missing_at' => $result->deactivationEligible
                            ? ($state['confirmed_missing_at'] ?? $capturedAt)
                            : $state['confirmed_missing_at'],
                        'consecutive_missing_count' => $result->consecutiveMissingCount,
                        'first_missing_at' => $result->firstMissingAt,
                        'presence_status' => $result->nextPresenceStatus,
                    ];
                }

                $states[$stateKey] = $state;
                $latest[$stateKey] = [
                    'observation' => $observation,
                    'qualification' => $qualification,
                    'snapshot' => $snapshot,
                ];
            }
        }
        ksort($states);
        ksort($latest);

        return [$qualifications, $states, $latest, $reappearances];
    }

    private function qualificationWithReason(SupplierSnapshotQualificationResult $result, string $reason): SupplierSnapshotQualificationResult
    {
        $reasons = [...$result->freezeReasonCodes, $reason];
        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return new SupplierSnapshotQualificationResult(
            supplierKey: $result->supplierKey,
            snapshotId: $result->snapshotId,
            snapshotStatus: $result->snapshotStatus,
            observedAt: $result->observedAt,
            isSuccessful: $result->isSuccessful,
            isFullSnapshot: $result->isFullSnapshot,
            isSchemaValid: $result->isSchemaValid,
            isCountSafe: $result->isCountSafe,
            isDropSafe: $result->isDropSafe,
            qualifiesForPresenceTracking: false,
            freezeReasonCodes: $reasons,
            requiresHumanReview: true,
        );
    }

    /** @param array<string, mixed> $catalog @param array<string, array<string, mixed>> $states @param array<string, array<string, mixed>> $latest @param array<string, bool> $reappearances @return array<int, array<string, mixed>> */
    private function offerRows(
        OperationalSupplierOfferEvidenceBundle $bundle,
        array $catalog,
        array $states,
        array $latest,
        array $reappearances,
        CarbonImmutable $evaluatedAt,
    ): array {
        $rows = [];
        foreach ($latest as $stateKey => $latestEvidence) {
            [$supplierKey, $supplierSkuHash] = explode('|', $stateKey, 2);
            $observation = $latestEvidence['observation'];
            /** @var SupplierSnapshotQualificationResult $qualification */
            $qualification = $latestEvidence['qualification'];
            $snapshot = $latestEvidence['snapshot'];
            $state = $states[$stateKey];
            $offers = $catalog['offer_index'][$stateKey] ?? [];
            $staged = $catalog['staged_index'][$stateKey] ?? [];
            $reasons = $qualification->qualifiesForPresenceTracking ? [] : ['snapshot_not_qualified', ...$qualification->freezeReasonCodes];
            $sourceOnly = $observation['present'] && $offers === [] && $staged === [];
            $productId = null;
            if (count($offers) === 1) {
                $productId = (int) $offers[0]->product_id;
            } elseif ($offers === [] && count($staged) === 1 && $staged[0]->product_id !== null) {
                $productId = (int) $staged[0]->product_id;
                $reasons[] = 'unresolved_manual_maintenance_protection';
            }
            if (count($offers) > 1 || count($staged) > 1 || $observation['duplicate_offer']) {
                $reasons[] = 'duplicate_offer';
            }
            if ($observation['identifier_conflict']) {
                $reasons[] = 'identifier_conflict';
            }
            if (! $observation['supplier_mapper_valid']) {
                $reasons[] = 'supplier_mapper_validation_failed';
            }
            if ($observation['blocking_validation_issue']) {
                $reasons[] = 'blocking_validation_issue';
            }
            if (! $observation['exact_supplier_sku_match']) {
                $reasons[] = 'supplier_sku_mismatch';
            }

            $present = (bool) $observation['present'];
            $price = $observation['price'];
            $zeroPrice = $present && ($price === null || DecimalNormalizer::compare($price, '0') <= 0);
            if ($zeroPrice) {
                $reasons[] = 'zero_or_invalid_price';
            }

            $freshness = $bundle->freshnessPolicies[$supplierKey] ?? null;
            $stale = false;
            if ($present && ($freshness === null || ! $freshness['approved'])) {
                $reasons[] = 'missing_supplier_freshness_policy';
            } elseif ($present) {
                $authoritativeAt = CarbonImmutable::parse($snapshot['authoritative_snapshot_at']);
                $ageSeconds = $authoritativeAt->diffInSeconds($evaluatedAt, false);
                if ($ageSeconds < 0) {
                    $reasons[] = 'snapshot_chronology_invalid';
                } elseif ($ageSeconds > ($freshness['max_age_hours'] * 3600)) {
                    $stale = true;
                    $reasons[] = 'stale_snapshot';
                }
            }

            $canonicalStatus = null;
            $mapperValid = $observation['supplier_mapper_valid'];
            if ($present && ! $zeroPrice && $mapperValid) {
                if ($supplierKey === 'apcom') {
                    try {
                        if ($observation['raw_quantity_observed'] === null || $observation['eol_flag'] === null) {
                            throw new InvalidArgumentException('missing_apcom_mapper_evidence');
                        }
                        $canonicalStatus = $this->apcomAvailabilityMapper
                            ->map($observation['raw_quantity_observed'], $observation['eol_flag'])
                            ->canonicalPublicStatus->value;
                    } catch (Throwable) {
                        $mapperValid = false;
                        $reasons[] = 'supplier_mapper_validation_failed';
                    }
                } else {
                    $canonicalStatus = $observation['canonical_public_status'];
                    if ($canonicalStatus === null) {
                        $mapperValid = false;
                        $reasons[] = 'supplier_mapper_validation_failed';
                    }
                }
            }

            $confirmedMissing = ! $present && $state['presence_status'] === 'inactive_missing_from_feed';
            if ($confirmedMissing) {
                $canonicalStatus = 'unavailable';
            }
            $blocked = $reasons !== [] || $zeroPrice || $stale || (! $present && ! $confirmedMissing);
            $valid = ($present && ! $blocked && $mapperValid && $canonicalStatus !== null) || ($confirmedMissing && ! $blocked);
            $commerciallyActive = $valid && in_array($canonicalStatus, ['in_stock', 'limited', 'on_request', 'last_units'], true);
            $recommendation = 'manual_review';
            if ($reasons !== [] || $zeroPrice || $stale) {
                $recommendation = 'manual_review';
            } elseif ($state['presence_status'] === 'inactive_missing_from_feed') {
                $recommendation = 'would_deactivate_offer';
            } elseif (isset($reappearances[$stateKey]) && ! $blocked) {
                $recommendation = 'would_reactivate_offer';
            } elseif ($commerciallyActive) {
                $recommendation = 'keep_active';
            }
            if ($sourceOnly) {
                $recommendation = 'manual_review';
            }

            $reasons = array_values(array_unique($reasons));
            sort($reasons, SORT_STRING);
            $rows[] = CanonicalOnboardingData::normalize([
                'aggregation_offer' => [
                    'blocked' => $blocked,
                    'canonical_public_status' => $canonicalStatus ?? 'unknown',
                    'valid' => $valid,
                ],
                'classification' => $sourceOnly ? 'potential_create' : ($present ? 'present' : 'source_absent'),
                'canonical_public_status' => $canonicalStatus,
                'confirmed_missing_at_internal' => $state['confirmed_missing_at'],
                'consecutive_missing_count' => $state['consecutive_missing_count'],
                'first_missing_at' => $state['first_missing_at']?->toAtomString(),
                'mpn_inferred' => false,
                'presence_status' => $state['presence_status'],
                'product_id_internal' => $productId,
                'product_reference_hash' => $productId === null ? null : $this->hasher->product($productId),
                'reason_codes' => $reasons,
                'recommendation' => $recommendation,
                'source_only' => $sourceOnly,
                'stale' => $stale,
                'supplier' => $supplierKey,
                'supplier_sku_hash' => $supplierSkuHash,
                'zero_price' => $zeroPrice,
            ]);
        }
        usort($rows, static fn (array $left, array $right): int => ($left['supplier'] <=> $right['supplier']) ?: ($left['supplier_sku_hash'] <=> $right['supplier_sku_hash']));

        return $rows;
    }

    /** @param array<string, mixed> $catalog @param array<int, array<string, mixed>> $offerRows @return array<int, array<string, mixed>> */
    private function productRows(OperationalSupplierOfferEvidenceBundle $bundle, array $catalog, array $offerRows, CarbonImmutable $evaluatedAt): array
    {
        $rowsByProduct = [];
        foreach ($offerRows as $row) {
            if ($row['product_id_internal'] !== null) {
                $rowsByProduct[(int) $row['product_id_internal']][] = $row;
            }
        }

        $result = [];
        $evaluatedProductHashes = [];
        foreach ($rowsByProduct as $productId => $rows) {
            /** @var Product|null $product */
            $product = $catalog['products']->get($productId);
            $productHash = $this->hasher->product($productId);
            $evaluatedProductHashes[$productHash] = true;
            $reasons = [];
            if ($product === null || $product->trashed()) {
                $reasons[] = 'disabled_or_deleted_record';
            } elseif ((bool) $product->manual_override) {
                $reasons[] = 'manual_override';
            } elseif ($product->source === Product::SOURCE_MANUAL) {
                $reasons[] = 'manual_product_excluded';
            } elseif ($product->source !== Product::SOURCE_SUPPLIER_IMPORT) {
                $reasons[] = 'unresolved_manual_maintenance_protection';
            }
            if ($product !== null && ! in_array($product->workflow_status, self::PUBLIC_WORKFLOW_STATES, true)) {
                $reasons[] = 'non_public_workflow_state';
            }

            $allCurrentOfferCount = (int) ($catalog['all_offer_counts']->get($productId) ?? 0);
            if ($allCurrentOfferCount !== count($rows)) {
                $reasons[] = 'unprovable_continuous_absence';
            }

            $aggregation = $this->offerAggregationPolicy->preview(new CatalogOfferAggregationInput(
                productReferenceHash: $productHash,
                offers: array_values(array_map(static fn (array $row): array => $row['aggregation_offer'], $rows)),
            ));
            $recommendation = 'manual_review';
            $visibility = null;
            $hasPresentZeroOrStale = collect($rows)->contains(static fn (array $row): bool => $row['zero_price'] || $row['stale']);

            if ($reasons === [] && $aggregation->hasActiveCommercialOffer) {
                $recommendation = 'keep_active';
                $visibility = $this->visibilityLifecyclePolicy->preview(new CatalogProductVisibilityLifecycleInput(
                    productReferenceHash: $productHash,
                    zeroActiveOffersSince: null,
                    evaluatedAt: $evaluatedAt,
                    hasActiveCommercialOffer: true,
                    canonicalPublicStatus: $aggregation->selectedCanonicalPublicStatus,
                ));
            } elseif ($reasons === [] && ! $hasPresentZeroOrStale && $aggregation->blockedOfferCount === 0) {
                $lifecycleEvidence = $bundle->productLifecycleEvidence[$productHash] ?? null;
                $derivedZeroSince = collect($rows)
                    ->pluck('confirmed_missing_at_internal')
                    ->filter()
                    ->reduce(static function (?CarbonImmutable $latest, string $candidate): CarbonImmutable {
                        $candidateAt = CarbonImmutable::parse($candidate);

                        return $latest === null || $candidateAt->greaterThan($latest) ? $candidateAt : $latest;
                    });
                if ($lifecycleEvidence === null
                    || ! $lifecycleEvidence['continuous_qualified_absence_proven']
                    || $lifecycleEvidence['zero_active_offers_since'] === null
                    || $derivedZeroSince === null
                    || $lifecycleEvidence['zero_active_offers_since'] !== $derivedZeroSince->toAtomString()) {
                    $reasons[] = 'unprovable_continuous_absence';
                } else {
                    $zeroSince = CarbonImmutable::parse($lifecycleEvidence['zero_active_offers_since']);
                    if ($zeroSince->greaterThan($evaluatedAt)) {
                        $reasons[] = 'unprovable_continuous_absence';
                    } else {
                        $visibility = $this->visibilityLifecyclePolicy->preview(new CatalogProductVisibilityLifecycleInput(
                            productReferenceHash: $productHash,
                            zeroActiveOffersSince: $zeroSince,
                            evaluatedAt: $evaluatedAt,
                            hasActiveCommercialOffer: false,
                            canonicalPublicStatus: $aggregation->selectedCanonicalPublicStatus,
                        ));
                        $recommendation = match ($visibility->visibilityState) {
                            'cold_archive_candidate' => 'would_mark_cold_archive_candidate',
                            'archived_noindex' => 'would_mark_archived_noindex',
                            default => 'would_mark_unavailable',
                        };
                    }
                }
            }

            if ($reasons !== []) {
                $recommendation = 'manual_review';
            }
            $reasons = array_values(array_unique($reasons));
            sort($reasons, SORT_STRING);

            $deletion = $this->deletionPolicy->preview(new CatalogProductDeletionPolicyInput(
                productReferenceHash: $productHash,
                hasEverBeenPublished: $product?->published_at !== null,
                hasOrderHistory: false,
                hasSupplierHistory: true,
                hasActiveSupplierOffer: $aggregation->hasActiveCommercialOffer,
                hasRequiredRelationalDependency: true,
                isDemonstrablyTestDuplicateOrErroneous: false,
                hasExplicitDuplicateConsolidationPlan: false,
                seoRedirectReviewed: false,
                previewAndBackupExist: false,
            ));

            $result[] = CanonicalOnboardingData::normalize([
                'active_alternative_present' => $aggregation->validActiveOfferCount > 0
                    && collect($rows)->contains(static fn (array $row): bool => $row['supplier'] === 'apcom' && $row['recommendation'] !== 'keep_active'),
                'aggregation' => $aggregation->toArray(),
                'delete_allowed' => false,
                'deletion_policy_classification' => $deletion->manualReviewClassification,
                'product_reference_hash' => $productHash,
                'reason_codes' => $reasons,
                'recommendation' => $recommendation,
                'visibility_preview' => $visibility?->toArray(),
            ]);
        }
        foreach (array_keys($bundle->productLifecycleEvidence) as $productHash) {
            if (! isset($evaluatedProductHashes[$productHash])) {
                throw new InvalidArgumentException('unproven_product_lifecycle_evidence');
            }
        }
        usort($result, static fn (array $left, array $right): int => $left['product_reference_hash'] <=> $right['product_reference_hash']);

        return $result;
    }

    /** @param array<int, SupplierSnapshotQualificationResult> $qualifications @param array<int, array<string, mixed>> $offerRows @param array<int, array<string, mixed>> $productRows @param array<string, bool> $reappearances @return array<string, int> */
    private function counts(array $qualifications, array $offerRows, array $productRows, array $reappearances): array
    {
        $counts = [
            'active_alternative_count' => count(array_filter($productRows, static fn (array $row): bool => $row['active_alternative_present'])),
            'confirmed_missing_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['presence_status'] === 'inactive_missing_from_feed')),
            'frozen_snapshot_count' => count(array_filter($qualifications, static fn (SupplierSnapshotQualificationResult $result): bool => ! $result->qualifiesForPresenceTracking)),
            'missing_candidate_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['consecutive_missing_count'] > 0 && $row['presence_status'] !== 'inactive_missing_from_feed')),
            'no_sellable_offer_count' => count(array_filter($productRows, static fn (array $row): bool => ! $row['aggregation']['has_active_commercial_offer'])),
            'present_offer_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['classification'] === 'present')),
            'qualified_snapshot_count' => count(array_filter($qualifications, static fn (SupplierSnapshotQualificationResult $result): bool => $result->qualifiesForPresenceTracking)),
            'reappearance_count' => count($reappearances),
            'source_only_potential_create_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['source_only'] && $row['supplier'] === 'apcom')),
            'stale_offer_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['stale'])),
            'zero_price_manual_review_count' => count(array_filter($offerRows, static fn (array $row): bool => $row['zero_price'])),
        ];
        ksort($counts);

        return $counts;
    }

    /** @param array<int, SupplierSnapshotQualificationResult> $qualifications @param array<int, array<string, mixed>> $offerRows @param array<int, array<string, mixed>> $productRows @return array<string, int> */
    private function reasonCounts(array $qualifications, array $offerRows, array $productRows): array
    {
        $counts = [];
        foreach ($qualifications as $qualification) {
            foreach ($qualification->freezeReasonCodes as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        foreach ([...$offerRows, ...$productRows] as $row) {
            foreach ($row['reason_codes'] as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @param array<int, array<string, mixed>> $offerRows @param array<int, array<string, mixed>> $productRows @return array<string, int> */
    private function recommendationCounts(array $offerRows, array $productRows): array
    {
        $counts = [];
        foreach ([...$offerRows, ...$productRows] as $row) {
            $recommendation = $row['recommendation'];
            $counts[$recommendation] = ($counts[$recommendation] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function protectedStateSignature(): string
    {
        $state = [];
        foreach (self::PROTECTED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $state[$table] = ['exists' => false];

                continue;
            }
            $entry = [
                'count' => (int) DB::table($table)->count(),
                'exists' => true,
            ];
            if (Schema::hasColumn($table, 'id')) {
                $entry['maximum_id'] = DB::table($table)->max('id');
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $entry['maximum_updated_at'] = DB::table($table)->max('updated_at');
            }
            $state[$table] = $entry;
        }
        ksort($state);

        return hash('sha256', CanonicalOnboardingData::encode($state));
    }

    /** @param array<string, mixed> $catalog @param array<string, array<string, mixed>> $latest */
    private function assertCatalogEvidenceCoverage(array $catalog, array $latest): void
    {
        foreach (array_unique([...array_keys($catalog['offer_index']), ...array_keys($catalog['staged_index'])]) as $stateKey) {
            if (! isset($latest[$stateKey])) {
                throw new InvalidArgumentException('incomplete_offer_presence_observations');
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicOfferRow(array $row): array
    {
        unset($row['aggregation_offer'], $row['confirmed_missing_at_internal'], $row['product_id_internal']);

        return $row;
    }
}
