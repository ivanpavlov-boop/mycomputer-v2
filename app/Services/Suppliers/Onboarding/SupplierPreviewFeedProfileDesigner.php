<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesign;
use App\Data\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignReport;

/**
 * Builds a non-persisted human-review design report from the existing read-only reconciler.
 */
final class SupplierPreviewFeedProfileDesigner
{
    /** @var array<int, string> */
    private const PROTECTED_TABLES = [
        'suppliers',
        'supplier_products',
        'products',
        'categories',
        'supplier_category_mappings',
        'canonical_product_families',
        'category_product_attributes',
        'product_attributes',
        'attribute_values',
        'product_attribute_values',
        'catalog_sync_batches',
        'catalog_sync_logs',
        'supplier_import_runs',
        'import_jobs',
        'catalog_sync',
    ];

    public function __construct(
        private readonly SupplierHumanDecisionRegistry $decisionRegistry,
        private readonly SupplierHumanDecisionRegisterValidator $decisionValidator,
        private readonly SupplierPreviewFeedProfileDesignRegistry $profileRegistry,
        private readonly SupplierPreviewFeedProfileDesignValidator $profileValidator,
        private readonly ApcomAvailabilityMapper $apcomAvailabilityMapper,
        private readonly ApcomAuthoritativeBusinessPolicy $apcomBusinessPolicy,
        private readonly SupplierFeedProfileApprovalGateFactory $approvalGateFactory,
        private readonly LocalSupplierSourceStagingReconciler $reconciler,
    ) {}

    /** @param array<string, mixed> $options */
    public function design(array $options): SupplierPreviewFeedProfileDesignReport
    {
        $registerKey = trim((string) ($options['decision_register'] ?? ''));
        $profileKey = trim((string) ($options['preview_profile'] ?? SupplierPreviewFeedProfileDesignRegistry::APCOM_PROFILE));
        $register = $this->decisionRegistry->find($registerKey);
        $profile = $this->profileRegistry->find($profileKey);

        if ($register === null || $profile === null) {
            return $this->failure(array_filter([
                $register === null ? 'unknown_decision_register' : null,
                $profile === null ? 'unknown_preview_feed_profile' : null,
            ]));
        }

        if (trim((string) ($options['semantics_profile'] ?? '')) !== $profile->semanticsProfileKey) {
            return $this->failure(
                ['preview_profile_semantics_mismatch'],
                $register->toArray(),
                $profile->toArray(),
            );
        }

        $decisionValidation = $this->decisionValidator->validate($register);
        $profileValidation = $this->profileValidator->validate($profile, $register);
        if (! $decisionValidation['valid'] || ! $profileValidation['valid']) {
            return $this->failure(
                array_merge((array) $decisionValidation['errors'], (array) $profileValidation['errors']),
                $register->toArray(),
                $profile->toArray(),
                $decisionValidation,
                $profileValidation,
            );
        }

        $reconciliation = $this->reconciler->reconcile($options)->toArray();
        $hardBlockers = array_values(array_unique(array_merge(
            (array) ($reconciliation['blockers'] ?? []),
            array_sum((array) ($reconciliation['records_changed'] ?? [])) === 0 ? [] : ['protected_state_changed'],
        )));
        sort($hardBlockers, SORT_STRING);

        $success = (bool) ($reconciliation['success'] ?? false) && $hardBlockers === [];
        $payload = [
            'decision_register' => $register->toArray(),
            'decision_register_validation' => $decisionValidation,
            'preview_feed_profile' => $profile->toArray(),
            'preview_feed_profile_validation' => $profileValidation,
            'human_review_required' => true,
            'blocking_decision_ids' => $this->blockingDecisionIds($register),
            'aggregate_preview_counts' => $this->aggregatePreviewCounts($reconciliation),
            'candidate_classifications' => $this->candidateClassifications($reconciliation),
            'bounded_hash_samples' => (array) ($reconciliation['bounded_hash_samples'] ?? []),
            'raw_values_emitted' => false,
            'source_to_staging_reconciliation' => $this->reconciliationSummary($reconciliation),
            'protected_counts_before' => (array) ($reconciliation['protected_counts_before'] ?? []),
            'protected_counts_after' => (array) ($reconciliation['protected_counts_after'] ?? []),
            'protected_state_fingerprints_before' => (array) ($reconciliation['protected_state_fingerprints_before'] ?? []),
            'protected_state_fingerprints_after' => (array) ($reconciliation['protected_state_fingerprints_after'] ?? []),
            'records_changed' => $this->recordsChanged($reconciliation),
            'persisted_profile_created' => false,
            'executable_import_configuration_created' => false,
            'import_executed' => false,
            'catalog_sync_executed' => false,
            'links_changed' => false,
            'schedule_changed' => false,
            'images_imported' => false,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'staging_write_allowed' => false,
            'profile_persistence_allowed' => false,
            'blockers' => $hardBlockers,
            'warnings' => $this->warnings($reconciliation),
            'issue_counts' => [
                'blockers' => count($hardBlockers),
                'warnings' => count($this->warnings($reconciliation)),
            ],
            ...$this->v2Payload($profile, $register),
        ];

        return new SupplierPreviewFeedProfileDesignReport(
            success: $success,
            verdict: $success ? 'preview_feed_profile_requires_human_decisions' : 'preview_feed_profile_design_blocked',
            payload: $payload,
        );
    }

    /** @return array<int, string> */
    private function blockingDecisionIds(object $register): array
    {
        $ids = [];
        foreach ($register->decisions as $decision) {
            if ($decision->blockingDecision) {
                $ids[] = $decision->decisionId;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param array<string, mixed> $reconciliation @return array<string, int> */
    private function aggregatePreviewCounts(array $reconciliation): array
    {
        $exact = (array) ($reconciliation['exact_supplier_sku_reconciliation'] ?? []);

        return [
            'would_create' => (int) ($exact['source_only_sku_count'] ?? 0),
            'would_update' => (int) ($exact['exact_one_to_one_match_count'] ?? 0),
            'would_delete' => (int) ($exact['staging_only_sku_count'] ?? 0),
            'would_link' => (int) ($exact['matched_unlinked_staging_row_count'] ?? 0),
            'would_unlink' => (int) ($exact['staging_only_linked_row_count'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $reconciliation @return array<int, array<string, mixed>> */
    private function candidateClassifications(array $reconciliation): array
    {
        $exact = (array) ($reconciliation['exact_supplier_sku_reconciliation'] ?? []);
        $source = (array) ($reconciliation['source_aggregates'] ?? []);
        $prices = (array) ($source['price_candidates'] ?? []);
        $ean = (array) ($reconciliation['ean_diagnostics'] ?? []);
        $observedStock = (array) ($reconciliation['observed_stock_analysis'] ?? []);

        return [
            $this->classification('exact_match', (int) ($exact['exact_one_to_one_match_count'] ?? 0), 'APCOM-ID-001'),
            $this->classification('source_only', (int) ($exact['source_only_sku_count'] ?? 0), 'APCOM-SOURCE-ONLY-001'),
            $this->classification('staging_only', (int) ($exact['staging_only_sku_count'] ?? 0), 'APCOM-STAGING-ONLY-001'),
            $this->classification('matched_linked', (int) ($exact['matched_linked_staging_row_count'] ?? 0), 'APCOM-ID-001'),
            $this->classification('matched_unlinked', (int) ($exact['matched_unlinked_staging_row_count'] ?? 0), 'APCOM-ID-002'),
            $this->classification('staging_only_linked', (int) ($exact['staging_only_linked_row_count'] ?? 0), 'APCOM-LINKED-STAGING-ONLY-001'),
            $this->classification('staging_only_unlinked', (int) ($exact['staging_only_unlinked_row_count'] ?? 0), 'APCOM-STAGING-ONLY-001'),
            $this->classification('eol_review', (int) data_get($source, 'eol.one_count', 0), 'APCOM-EOL-REVIEW-001'),
            $this->classification('zero_price_review', max((int) data_get($prices, 'dac_price.zero_count', 0), (int) data_get($prices, 'fd_price.zero_count', 0)), 'APCOM-ZERO-PRICE-001'),
            $this->classification('blank_ean_review', (int) data_get($source, 'ean.blank_count', 0), 'APCOM-ID-002'),
            $this->classification('ean_conflict_review', (int) ($ean['cross_sku_ean_conflict_count'] ?? 0), 'APCOM-ID-002'),
            $this->classification('unresolved_stock_review', (int) ($observedStock['total_records'] ?? 0), 'APCOM-STOCK-001'),
        ];
    }

    /** @return array<string, mixed> */
    private function classification(string $classification, int $count, string $decisionId): array
    {
        return [
            'classification' => $classification,
            'count' => $count,
            'decision_id' => $decisionId,
            'preview_only' => true,
            'automatic_execution_allowed' => false,
            'raw_values_emitted' => false,
        ];
    }

    /** @param array<string, mixed> $reconciliation @return array<string, mixed> */
    private function reconciliationSummary(array $reconciliation): array
    {
        return [
            'supplier' => (array) ($reconciliation['supplier'] ?? []),
            'source' => (array) ($reconciliation['source'] ?? []),
            'source_fingerprint' => (array) ($reconciliation['source_fingerprint'] ?? []),
            'expected_state' => (array) ($reconciliation['expected_state'] ?? []),
            'observed_state' => (array) ($reconciliation['observed_state'] ?? []),
            'baseline_lock' => (array) ($reconciliation['baseline_lock'] ?? []),
            'active_import_check' => (array) ($reconciliation['active_import_check'] ?? []),
            'global_safety_flags' => (array) ($reconciliation['global_safety_flags'] ?? []),
            'semantics_profile' => (array) ($reconciliation['semantics_profile'] ?? []),
            'stock_semantics_discrepancy' => (array) ($reconciliation['stock_semantics_discrepancy'] ?? []),
            'source_aggregates' => (array) ($reconciliation['source_aggregates'] ?? []),
            'staging_aggregates' => (array) ($reconciliation['staging_aggregates'] ?? []),
            'exact_supplier_sku_reconciliation' => (array) ($reconciliation['exact_supplier_sku_reconciliation'] ?? []),
            'normalized_match_diagnostics' => (array) ($reconciliation['normalized_match_diagnostics'] ?? []),
            'ean_diagnostics' => (array) ($reconciliation['ean_diagnostics'] ?? []),
            'warnings' => $this->warnings($reconciliation),
        ];
    }

    /** @param array<string, mixed> $reconciliation @return array<string, int> */
    private function recordsChanged(array $reconciliation): array
    {
        $records = (array) ($reconciliation['records_changed'] ?? []);
        foreach (self::PROTECTED_TABLES as $table) {
            $records[$table] = (int) ($records[$table] ?? 0);
        }
        ksort($records);

        return $records;
    }

    /** @param array<string, mixed> $reconciliation @return array<int, string> */
    private function warnings(array $reconciliation): array
    {
        $warnings = array_values(array_unique((array) ($reconciliation['warnings'] ?? [])));
        sort($warnings, SORT_STRING);

        return $warnings;
    }

    /** @return array<string, mixed> */
    private function v2Payload(SupplierPreviewFeedProfileDesign $profile, SupplierHumanDecisionRegister $register): array
    {
        if ($profile->key !== SupplierPreviewFeedProfileDesignRegistry::APCOM_PROFILE_V2) {
            return [];
        }

        $examples = [];
        foreach ([[0, 0], [1, 0], [5, 0], [6, 0], [40, 0], [100, 0], [3, 1], [100, 1], [0, 1]] as [$stock, $eol]) {
            $examples[] = $this->apcomAvailabilityMapper->map($stock, $eol)->toArray();
        }

        return [
            'availability_mapping_preview' => $examples,
            'canonical_status_model' => $this->apcomBusinessPolicy->canonicalStatusModel(),
            'green_tax_policy' => $this->apcomBusinessPolicy->greenTaxPolicy(),
            'lifecycle_mapping_preview' => array_map(static fn (array $example): array => [
                'canonical_lifecycle_status' => $example['canonical_lifecycle_status'],
                'canonical_public_status' => $example['canonical_public_status'],
                'orderable_in_principle' => $example['orderable_in_principle'],
                'raw_quantity_observed' => $example['raw_quantity_observed'],
            ], $examples),
            'price_mapping_preview' => $this->apcomBusinessPolicy->priceMappingPreview(),
            'profile_approval_gate' => $this->approvalGateFactory->create($profile, $register)->toArray(),
            'public_quantity_policy' => $this->apcomBusinessPolicy->publicQuantityPolicy(),
            'supplier_availability_policy' => $this->apcomAvailabilityMapper->policy(),
        ];
    }

    /** @param array<int, string> $blockers @param array<string, mixed> $register @param array<string, mixed> $profile @param array<string, mixed> $decisionValidation @param array<string, mixed> $profileValidation */
    private function failure(array $blockers, array $register = [], array $profile = [], array $decisionValidation = [], array $profileValidation = []): SupplierPreviewFeedProfileDesignReport
    {
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return new SupplierPreviewFeedProfileDesignReport(false, 'preview_feed_profile_design_blocked', [
            'decision_register' => $register,
            'decision_register_validation' => $decisionValidation,
            'preview_feed_profile' => $profile,
            'preview_feed_profile_validation' => $profileValidation,
            'human_review_required' => true,
            'blocking_decision_ids' => [],
            'aggregate_preview_counts' => ['would_create' => 0, 'would_update' => 0, 'would_delete' => 0, 'would_link' => 0, 'would_unlink' => 0],
            'candidate_classifications' => [],
            'bounded_hash_samples' => [],
            'raw_values_emitted' => false,
            'source_to_staging_reconciliation' => [],
            'protected_counts_before' => [],
            'protected_counts_after' => [],
            'protected_state_fingerprints_before' => [],
            'protected_state_fingerprints_after' => [],
            'records_changed' => array_fill_keys(self::PROTECTED_TABLES, 0),
            'persisted_profile_created' => false,
            'executable_import_configuration_created' => false,
            'import_executed' => false,
            'catalog_sync_executed' => false,
            'links_changed' => false,
            'schedule_changed' => false,
            'images_imported' => false,
            'automatic_execution_allowed' => false,
            'catalog_write_allowed' => false,
            'staging_write_allowed' => false,
            'profile_persistence_allowed' => false,
            'blockers' => $blockers,
            'warnings' => [],
            'issue_counts' => ['blockers' => count($blockers), 'warnings' => 0],
        ]);
    }
}
