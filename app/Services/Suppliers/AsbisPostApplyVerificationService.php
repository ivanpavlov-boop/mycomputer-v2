<?php

namespace App\Services\Suppliers;

use App\Models\AvailabilityStatus;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AsbisPostApplyVerificationService
{
    private const SUPPLIER_KEY = 'asbis';

    private const PROTECTED_TABLES = [
        'products',
        'supplier_products',
        'suppliers',
        'categories',
        'supplier_category_mappings',
        'canonical_product_families',
        'category_product_attributes',
        'product_attributes',
        'attribute_values',
        'product_attribute_values',
        'catalog_sync_batches',
        'catalog_sync_logs',
    ];

    private const CANONICAL_FIELDS = [
        'supplier_id',
        'supplier_feed_id',
        'product_id',
        'supplier_sku',
        'ean',
        'mpn',
        'name',
        'brand_name',
        'category_name',
        'price',
        'supplier_price_raw',
        'recommended_price',
        'quantity',
        'external_availability_status',
        'external_availability_label',
        'availability_status_id',
        'currency',
        'raw_data',
        'payload_hash',
        'synced_at',
        'status',
        'mapping_notes',
    ];

    private const DECIMAL_FIELDS = [
        'price',
        'supplier_price_raw',
        'recommended_price',
    ];

    private const KNOWN_AVAILABILITY = [
        'in_stock',
        'limited_stock',
        'on_request',
        'out_of_stock',
    ];

    /** @var array<string, int> */
    private array $issueCounts = [];

    /** @var array<int, array<string, mixed>> */
    private array $issueSamples = [];

    private int $issueSampleLimit = 20;

    public function __construct(
        private readonly AsbisApplyReadinessAuditService $audit,
        private readonly AsbisCandidateFingerprintService $candidateFingerprint,
        private readonly AsbisStagingPayloadSchemaValidator $schemaValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $startedAt = microtime(true);
        $this->issueCounts = [];
        $this->issueSamples = [];
        $this->issueSampleLimit = (bool) ($options['summary_only'] ?? false)
            ? 0
            : $this->boundedLimit($options['issue_sample_limit'] ?? 20);
        $protectedBefore = $this->protectedCounts();
        $audit = $this->audit->run([
            ...$options,
            'mode' => 'post_apply_verification',
            'full_file' => true,
            'include_candidate_payloads' => true,
            'ignore_existing_supplier_products' => true,
        ]);

        if (! ($audit['success'] ?? false)) {
            $this->addIssue($this->mapAuditFailure($audit));

            return $this->result(
                $audit,
                null,
                [],
                $protectedBefore,
                $startedAt,
                $options
            );
        }

        $supplier = $this->supplierFromAudit($audit);

        if (! $supplier instanceof Supplier || $this->supplierKey($supplier) !== self::SUPPLIER_KEY) {
            $this->addIssue('supplier_must_be_asbis');

            return $this->result($audit, $supplier, [], $protectedBefore, $startedAt, $options);
        }

        $candidates = is_array($audit['candidate_payloads'] ?? null)
            ? array_values($audit['candidate_payloads'])
            : [];
        $schema = $this->schemaValidator->validate($candidates);
        $candidateFingerprint = $this->candidateFingerprint->fingerprint($candidates);

        $this->verifyAudit($audit, $supplier);
        $this->verifyExpectedValues($audit, $candidates, $candidateFingerprint, $options);
        $this->verifyFeatureFlags($supplier);
        $this->verifySchema($audit, $schema);
        $this->verifySourceFingerprints($audit, $options);

        $stagedRows = SupplierProduct::query()
            ->where('supplier_id', $supplier->getKey())
            ->get(self::CANONICAL_FIELDS)
            ->all();
        $this->verifyDatabaseCounts(count($stagedRows), $options);
        $skuReconciliation = $this->reconcileSkus($candidates, $stagedRows);
        $rowReconciliation = $this->reconcileRows($candidates, $stagedRows);
        $provenance = $this->verifyProvenance($candidates, $stagedRows, $audit['source_fingerprints'] ?? []);
        $truncation = $this->verifyTruncation($candidates);
        $availability = $this->verifyAvailability($candidates, $rowReconciliation);
        $pricing = $this->verifyPricing($candidates, $rowReconciliation);
        $protectedAfter = $this->protectedCounts();

        foreach ($protectedBefore as $table => $count) {
            if (($protectedAfter[$table] ?? null) !== $count) {
                $this->addIssue('database_count_changed', ['table' => $table]);
            }
        }

        return $this->result(
            $audit,
            $supplier,
            $candidates,
            $protectedBefore,
            $startedAt,
            $options,
            [
                'calculated_candidate_sha256' => $candidateFingerprint,
                'payload_schema_compatibility' => $schema,
                'database_counts' => [
                    'protected_before' => $protectedBefore,
                    'protected_after' => $protectedAfter,
                    'protected_unchanged' => $protectedBefore === $protectedAfter,
                    'asbis_supplier_products' => count($stagedRows),
                    'total_supplier_products' => SupplierProduct::query()->count(),
                ],
                'sku_reconciliation' => $skuReconciliation,
                'row_reconciliation' => $rowReconciliation,
                'provenance_verification' => $provenance,
                'truncation_verification' => $truncation,
                'availability_verification' => $availability,
                'pricing_verification' => $pricing,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, int>  $protectedBefore
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function result(array $audit, ?Supplier $supplier, array $candidates, array $protectedBefore, float $startedAt, array $options, array $details = []): array
    {
        $protectedAfter = $this->protectedCounts();
        $passed = $this->issueCounts === [];
        $verdict = $this->verdict();
        $sourceFingerprints = $audit['source_fingerprints'] ?? [];
        $expectedTotal = $this->optionalInt($options['expected_total_staged_count'] ?? null);
        $totalStaged = SupplierProduct::query()->count();

        return [
            'success' => $passed,
            'mode' => 'post_apply_verification',
            'read_only' => true,
            'verification_passed' => $passed,
            'verdict' => $verdict,
            'supplier' => $supplier instanceof Supplier ? $this->safeSupplier($supplier) : ($audit['supplier'] ?? null),
            'feature_flags' => $this->featureFlags($supplier),
            'schedule_enabled' => $supplier instanceof Supplier ? (bool) $supplier->schedule_enabled : null,
            'source_fingerprints' => [
                'expected_product_list_sha256' => $this->optionString($options, 'expected_product_list_sha256'),
                'actual_product_list_sha256' => $sourceFingerprints['product_list_sha256'] ?? null,
                'product_list_match' => $this->hashMatches($options['expected_product_list_sha256'] ?? null, $sourceFingerprints['product_list_sha256'] ?? null),
                'expected_price_avail_sha256' => $this->optionString($options, 'expected_price_avail_sha256'),
                'actual_price_avail_sha256' => $sourceFingerprints['price_avail_sha256'] ?? null,
                'price_avail_match' => $this->hashMatches($options['expected_price_avail_sha256'] ?? null, $sourceFingerprints['price_avail_sha256'] ?? null),
            ],
            'candidate_payload_schema_version' => $audit['candidate_payload_schema_version'] ?? null,
            'expected_candidate_sha256' => $this->optionString($options, 'expected_candidate_sha256'),
            'calculated_candidate_sha256' => $details['calculated_candidate_sha256'] ?? $this->candidateFingerprint->fingerprint($candidates),
            'expected_candidate_count' => $this->optionalInt($options['expected_ready_count'] ?? null),
            'calculated_candidate_count' => count($candidates),
            'payload_schema_compatibility' => $details['payload_schema_compatibility'] ?? [
                'payload_schema_compatible' => false,
            ],
            'database_counts' => $details['database_counts'] ?? [
                'protected_before' => $protectedBefore,
                'protected_after' => $protectedAfter,
                'protected_unchanged' => $protectedBefore === $protectedAfter,
                'asbis_supplier_products' => $supplier instanceof Supplier ? SupplierProduct::query()->where('supplier_id', $supplier->getKey())->count() : 0,
                'total_supplier_products' => $totalStaged,
            ],
            'sku_reconciliation' => $details['sku_reconciliation'] ?? [],
            'row_reconciliation' => $details['row_reconciliation'] ?? [],
            'provenance_verification' => $details['provenance_verification'] ?? [],
            'truncation_verification' => $details['truncation_verification'] ?? [],
            'availability_verification' => $details['availability_verification'] ?? [],
            'pricing_verification' => $details['pricing_verification'] ?? [],
            'issue_counts' => $this->issueCounts,
            'issues' => $this->issueSamples,
            'issue_samples' => $this->issueSamples,
            'records_changed' => $this->recordsChanged(),
            'elapsed_seconds' => round(microtime(true) - $startedAt, 4),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'expected_total_staged_count' => $expectedTotal,
            'sample_limit' => $this->boundedLimit($options['sample_limit'] ?? 20),
            'issue_sample_limit' => $this->issueSampleLimit,
        ];
    }

    /** @param array<string, mixed> $audit */
    private function verifyAudit(array $audit, Supplier $supplier): void
    {
        if (! data_get($audit, 'parser.full_file_completed', false)) {
            $this->addIssue('source_not_fully_scanned');
        }

        if (! data_get($audit, 'reconciliation.reconciliation_valid', false)) {
            $this->addIssue('source_reconciliation_failed');
        }

        if ((int) data_get($audit, 'readiness.ready_to_update', 0) !== 0) {
            $this->addIssue('unexpected_ready_to_update_rows');
        }

        if ((int) data_get($audit, 'identifier_audit.duplicate_product_code_keys', 0) !== 0) {
            $this->addIssue('duplicate_product_code_keys');
        }

        if ((int) data_get($audit, 'identifier_audit.duplicate_wic_keys', 0) !== 0) {
            $this->addIssue('duplicate_wic_keys');
        }

        if ($this->supplierKey($supplier) !== self::SUPPLIER_KEY) {
            $this->addIssue('supplier_must_be_asbis');
        }
    }

    /** @param array<string, mixed> $options */
    private function verifyExpectedValues(array $audit, array $candidates, string $candidateFingerprint, array $options): void
    {
        $this->verifyRequiredHash($options, 'expected_product_list_sha256');
        $this->verifyRequiredHash($options, 'expected_price_avail_sha256');
        $this->verifyRequiredHash($options, 'expected_candidate_sha256');
        $this->verifyRequiredInt($options, 'expected_ready_count');
        $this->verifyRequiredInt($options, 'expected_asbis_staged_count');

        if (($options['expected_candidate_sha256'] ?? null) !== null && (string) $options['expected_candidate_sha256'] !== $candidateFingerprint) {
            $this->addIssue('candidate_set_fingerprint_mismatch');
        }

        if (($options['expected_ready_count'] ?? null) !== null && (int) $options['expected_ready_count'] !== count($candidates)) {
            $this->addIssue('candidate_count_mismatch');
        }
    }

    /** @param array<string, mixed> $options */
    private function verifySourceFingerprints(array $audit, array $options): void
    {
        foreach ([
            'expected_product_list_sha256' => 'product_list_sha256',
            'expected_price_avail_sha256' => 'price_avail_sha256',
        ] as $expected => $actual) {
            if (($options[$expected] ?? null) !== null && (string) $options[$expected] !== (string) data_get($audit, 'source_fingerprints.'.$actual)) {
                $this->addIssue('source_fingerprint_mismatch');
            }
        }
    }

    private function verifySchema(array $audit, array $schema): void
    {
        if (($audit['candidate_payload_schema_version'] ?? null) !== AsbisCandidateFingerprintService::SCHEMA_VERSION) {
            $this->addIssue('candidate_schema_mismatch');
        }

        if (! ($schema['payload_schema_compatible'] ?? false)) {
            $this->addIssue('payload_schema_incompatible');
        }
    }

    private function verifyFeatureFlags(Supplier $supplier): void
    {
        if ((bool) config('services.asbis_dual_feed_staging_apply.enabled', false)) {
            $this->addIssue('apply_feature_flag_enabled');
        }

        if (! (bool) config('catalog_sync.create_enabled', true)
            || (bool) config('catalog_sync.update_enabled', false)
            || (bool) config('catalog_sync.sync_all_enabled', false)
            || (bool) config('catalog_sync.auto_enabled', false)) {
            $this->addIssue('catalog_sync_flags_not_safe');
        }

        if ((bool) $supplier->schedule_enabled) {
            $this->addIssue('asbis_schedule_enabled');
        }
    }

    /** @param array<string, mixed> $options */
    private function verifyDatabaseCounts(int $asbisCount, array $options): void
    {
        if (($options['expected_asbis_staged_count'] ?? null) !== null && (int) $options['expected_asbis_staged_count'] !== $asbisCount) {
            $this->addIssue('expected_asbis_staged_count_mismatch');
        }

        if (($options['expected_total_staged_count'] ?? null) !== null && (int) $options['expected_total_staged_count'] !== SupplierProduct::query()->count()) {
            $this->addIssue('expected_total_staged_count_mismatch');
        }
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function reconcileSkus(array $candidates, array $stagedRows): array
    {
        $candidateCounts = [];
        $stagedCounts = [];
        $blankStaged = 0;

        foreach ($candidates as $candidate) {
            $sku = $this->normalizeSku($candidate['supplier_sku'] ?? null);
            if ($sku !== null) {
                $candidateCounts[$sku] = ($candidateCounts[$sku] ?? 0) + 1;
            }
        }

        foreach ($stagedRows as $row) {
            $sku = $this->normalizeSku($row->supplier_sku);
            if ($sku === null) {
                $blankStaged++;

                continue;
            }
            $stagedCounts[$sku] = ($stagedCounts[$sku] ?? 0) + 1;
        }

        $candidateKeys = array_keys($candidateCounts);
        $stagedKeys = array_keys($stagedCounts);
        $missing = array_values(array_diff($candidateKeys, $stagedKeys));
        $extra = array_values(array_diff($stagedKeys, $candidateKeys));
        $candidateDuplicates = count(array_filter($candidateCounts, fn (int $count): bool => $count > 1));
        $stagedDuplicates = count(array_filter($stagedCounts, fn (int $count): bool => $count > 1));

        if ($missing !== []) {
            $this->addIssue('missing_staged_skus', ['sample' => $this->skuHashes($missing)]);
        }
        if ($extra !== []) {
            $this->addIssue('extra_staged_skus', ['sample' => $this->skuHashes($extra)]);
        }
        if ($candidateDuplicates > 0 || $stagedDuplicates > 0 || $blankStaged > 0) {
            $this->addIssue('duplicate_or_blank_staged_skus');
        }

        return [
            'source_candidate_sku_count' => count($candidateKeys),
            'source_candidate_row_count' => count($candidates),
            'staged_unique_sku_count' => count($stagedKeys),
            'staged_row_count' => count($stagedRows),
            'missing_count' => count($missing),
            'extra_count' => count($extra),
            'missing_sku_hashes' => $this->skuHashes($missing),
            'extra_sku_hashes' => $this->skuHashes($extra),
            'source_duplicate_group_count' => $candidateDuplicates,
            'staged_duplicate_group_count' => $stagedDuplicates,
            'blank_staged_sku_count' => $blankStaged,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function reconcileRows(array $candidates, array $stagedRows): array
    {
        $stagedBySku = [];
        foreach ($stagedRows as $row) {
            $sku = $this->normalizeSku($row->supplier_sku);
            if ($sku !== null && ! isset($stagedBySku[$sku])) {
                $stagedBySku[$sku] = $row;
            }
        }

        $fieldMismatches = [];
        $payloadHashMismatch = 0;
        $nameMismatch = 0;
        $priceMismatch = 0;
        $availabilityMismatch = 0;
        $statusMismatch = 0;
        $rawDataMismatch = 0;
        $productLinked = 0;
        $synced = 0;
        $compared = 0;

        foreach ($candidates as $candidate) {
            $sku = $this->normalizeSku($candidate['supplier_sku'] ?? null);
            $row = $sku !== null ? ($stagedBySku[$sku] ?? null) : null;
            if (! $row) {
                continue;
            }

            $compared++;
            $mismatchFields = [];
            foreach (self::CANONICAL_FIELDS as $field) {
                if ($this->canonicalValue($field, $candidate[$field] ?? null) !== $this->canonicalValue($field, $row->{$field} ?? null)) {
                    $mismatchFields[] = $field;
                    $fieldMismatches[$field] = ($fieldMismatches[$field] ?? 0) + 1;
                }
            }

            if ($mismatchFields !== []) {
                $this->addIssue('canonical_row_mismatch', [
                    'sku_hash' => $this->skuHash($sku),
                    'fields' => $mismatchFields,
                ]);
            }
            if (in_array('payload_hash', $mismatchFields, true)) {
                $payloadHashMismatch++;
            }
            if (in_array('name', $mismatchFields, true)) {
                $nameMismatch++;
            }
            if (in_array('price', $mismatchFields, true) || in_array('supplier_price_raw', $mismatchFields, true)) {
                $priceMismatch++;
            }
            if (in_array('external_availability_status', $mismatchFields, true) || in_array('external_availability_label', $mismatchFields, true) || in_array('availability_status_id', $mismatchFields, true)) {
                $availabilityMismatch++;
            }
            if (in_array('status', $mismatchFields, true)) {
                $statusMismatch++;
            }
            if (in_array('raw_data', $mismatchFields, true)) {
                $rawDataMismatch++;
            }
            if ($row->product_id !== null) {
                $productLinked++;
            }
            if ($row->synced_at !== null) {
                $synced++;
            }
        }

        if ($compared !== count($candidates)) {
            $this->addIssue('canonical_rows_missing');
        }
        if ($productLinked > 0) {
            $this->addIssue('staged_rows_linked_to_products');
        }
        if ($synced > 0) {
            $this->addIssue('staged_rows_synced');
        }

        return [
            'compared_count' => $compared,
            'expected_count' => count($candidates),
            'field_mismatch_counts' => $fieldMismatches,
            'payload_hash_mismatch_count' => $payloadHashMismatch,
            'canonical_name_mismatch_count' => $nameMismatch,
            'price_mismatch_count' => $priceMismatch,
            'availability_mismatch_count' => $availabilityMismatch,
            'status_mismatch_count' => $statusMismatch,
            'raw_data_mismatch_count' => $rawDataMismatch,
            'product_linked_count' => $productLinked,
            'synced_row_count' => $synced,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function verifyProvenance(array $candidates, array $stagedRows, array $fingerprints): array
    {
        $rowsBySku = collect($stagedRows)->keyBy(fn (SupplierProduct $row): ?string => $this->normalizeSku($row->supplier_sku));
        $checked = 0;
        $mismatch = 0;
        foreach ($candidates as $candidate) {
            $sku = $this->normalizeSku($candidate['supplier_sku'] ?? null);
            $row = $sku !== null ? $rowsBySku->get($sku) : null;
            if (! $row) {
                continue;
            }
            $checked++;
            $raw = is_array($row->raw_data) ? $row->raw_data : [];
            $valid = ($raw['source'] ?? null) === 'asbis_dual_feed'
                && ($raw['supplier_key'] ?? null) === 'asbis'
                && $this->normalizeSku($raw['source_product_code'] ?? null) === $sku
                && $this->normalizeSku($raw['source_wic'] ?? null) === $sku
                && ($raw['product_list_sha256'] ?? null) === ($fingerprints['product_list_sha256'] ?? null)
                && ($raw['price_avail_sha256'] ?? null) === ($fingerprints['price_avail_sha256'] ?? null)
                && ($raw['candidate_payload_schema_version'] ?? null) === AsbisCandidateFingerprintService::SCHEMA_VERSION;
            if (! $valid) {
                $mismatch++;
                $this->addIssue('raw_provenance_mismatch', ['sku_hash' => $this->skuHash($sku)]);
            }
        }
        if ($checked !== count($candidates)) {
            $this->addIssue('provenance_rows_missing');
        }

        return [
            'checked_count' => $checked,
            'mismatch_count' => $mismatch,
            'source' => 'asbis_dual_feed',
            'supplier_key' => 'asbis',
            'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function verifyTruncation(array $candidates): array
    {
        $truncated = 0;
        $inconsistent = 0;
        $maxOriginal = 0;
        $maxStaged = 0;
        foreach ($candidates as $candidate) {
            $raw = is_array($candidate['raw_data'] ?? null) ? $candidate['raw_data'] : [];
            $name = $candidate['name'] ?? null;
            $stagedLength = $this->unicodeLength($name);
            $originalName = $raw['original_name'] ?? null;
            $originalLength = $raw['original_name_length'] ?? null;
            $wasTruncated = ($raw['name_was_truncated'] ?? false) === true;
            $maxOriginal = max($maxOriginal, is_int($originalLength) ? $originalLength : $stagedLength);
            $maxStaged = max($maxStaged, $stagedLength);
            if ($wasTruncated) {
                $truncated++;
            }
            $valid = $stagedLength <= 255
                && ($raw['staged_name_limit'] ?? null) === 255
                && ($raw['staged_name_length'] ?? null) === $stagedLength
                && is_int($originalLength)
                && $originalLength >= $stagedLength
                && ($wasTruncated
                    ? is_string($originalName) && $originalLength > 255 && $name === mb_substr($originalName, 0, 255, 'UTF-8') && $stagedLength === 255
                    : $originalName === null && $originalLength === $stagedLength);
            if (! $valid) {
                $inconsistent++;
                $this->addIssue('truncation_metadata_mismatch');
            }
        }

        return [
            'checked_count' => count($candidates),
            'truncated_name_count' => $truncated,
            'inconsistent_count' => $inconsistent,
            'maximum_original_name_length' => $maxOriginal,
            'maximum_staged_name_length' => $maxStaged,
            'staged_name_limit' => 255,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function verifyAvailability(array $candidates, array $rowReconciliation): array
    {
        $ids = collect($candidates)->pluck('availability_status_id')->filter()->unique()->values();
        $validIds = Schema::hasTable('availability_statuses')
            ? AvailabilityStatus::query()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];
        $invalidIds = $ids->diff($validIds)->count();
        $unknown = collect($candidates)->filter(fn (array $candidate): bool => ($candidate['external_availability_status'] ?? null) !== null && ! in_array($candidate['external_availability_status'], self::KNOWN_AVAILABILITY, true))->count();
        if ($invalidIds > 0) {
            $this->addIssue('invalid_availability_status');
        }
        if ($unknown > 0) {
            $this->addIssue('unknown_availability_status');
        }

        return [
            'checked_count' => count($candidates),
            'invalid_availability_status_id_count' => $invalidIds,
            'unknown_external_status_count' => $unknown,
            'availability_mismatch_count' => $rowReconciliation['availability_mismatch_count'] ?? 0,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function verifyPricing(array $candidates, array $rowReconciliation): array
    {
        $invalid = 0;
        $negative = 0;
        $currencyInvalid = 0;
        foreach ($candidates as $candidate) {
            $price = $candidate['price'] ?? null;
            $currency = $candidate['currency'] ?? null;
            if ($price === null || ! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $price)) {
                $invalid++;
            }
            if (is_numeric($price) && (float) $price < 0) {
                $negative++;
            }
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $currencyInvalid++;
            }
        }
        if ($invalid > 0) {
            $this->addIssue('invalid_price');
        }
        if ($negative > 0) {
            $this->addIssue('negative_price');
        }
        if ($currencyInvalid > 0) {
            $this->addIssue('invalid_currency');
        }

        return [
            'checked_count' => count($candidates),
            'invalid_price_count' => $invalid,
            'negative_price_count' => $negative,
            'invalid_currency_count' => $currencyInvalid,
            'price_mismatch_count' => $rowReconciliation['price_mismatch_count'] ?? 0,
            'supplier_cost_mismatch_count' => ($rowReconciliation['field_mismatch_counts']['supplier_price_raw'] ?? 0),
            'currency_mismatch_count' => ($rowReconciliation['field_mismatch_counts']['currency'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function protectedCounts(): array
    {
        return collect(self::PROTECTED_TABLES)->mapWithKeys(fn (string $table): array => [
            $table => Schema::hasTable($table) ? (int) Schema::getConnection()->table($table)->count() : 0,
        ])->all();
    }

    /** @return array<string, int> */
    private function recordsChanged(): array
    {
        return collect(self::PROTECTED_TABLES)->mapWithKeys(fn (string $table): array => [$table => 0])->all();
    }

    /** @return array<string, bool> */
    private function featureFlags(?Supplier $supplier): array
    {
        return [
            'asbis_dual_feed_staging_apply_enabled' => (bool) config('services.asbis_dual_feed_staging_apply.enabled', false),
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
            'asbis_schedule_enabled' => $supplier instanceof Supplier ? (bool) $supplier->schedule_enabled : false,
        ];
    }

    private function supplierFromAudit(array $audit): ?Supplier
    {
        $id = data_get($audit, 'supplier.id');

        return is_numeric($id) ? Supplier::query()->find((int) $id) : null;
    }

    private function supplierKey(Supplier $supplier): string
    {
        return Str::slug((string) ($supplier->slug ?: $supplier->company_name));
    }

    /** @return array<string, mixed> */
    private function safeSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getKey(),
            'name' => $supplier->company_name,
            'slug' => $supplier->slug,
            'key' => $this->supplierKey($supplier),
        ];
    }

    private function normalizeSku(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = Str::upper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function skuHash(?string $sku): ?string
    {
        return $sku === null ? null : hash('sha256', $sku);
    }

    /** @param array<int, string> $skus */
    private function skuHashes(array $skus): array
    {
        return array_slice(array_values(array_filter(array_map(fn (string $sku): ?string => $this->skuHash($sku), $skus))), 0, $this->issueSampleLimit);
    }

    private function unicodeLength(mixed $value): int
    {
        return $value === null ? 0 : mb_strlen((string) $value, 'UTF-8');
    }

    private function canonicalValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (in_array($field, self::DECIMAL_FIELDS, true)) {
            return $this->decimalString($value);
        }
        if ($field === 'raw_data') {
            return $this->canonicalize(is_array($value) ? $value : []);
        }
        if (in_array($field, ['supplier_id', 'supplier_feed_id', 'product_id', 'quantity', 'availability_status_id'], true)) {
            return $value === null ? null : (int) $value;
        }
        if ($field === 'synced_at') {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
        }
        if ($field === 'supplier_sku') {
            return $this->normalizeSku($value);
        }

        return is_scalar($value) ? trim((string) $value) : $value;
    }

    private function decimalString(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($negative ? substr($value, 1) : $value, '0');
        if ($value === '') {
            $value = '0';
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative && $whole !== '0' ? '-' : '').$whole.'.'.$fraction;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $this->canonicalize($item);
        }
        ksort($result);

        return $result;
    }

    /** @param array<string, mixed> $options */
    private function verifyRequiredHash(array $options, string $key): void
    {
        if (! isset($options[$key]) || trim((string) $options[$key]) === '') {
            $this->addIssue($key.'_required');
        }
    }

    /** @param array<string, mixed> $options */
    private function verifyRequiredInt(array $options, string $key): void
    {
        if (! array_key_exists($key, $options) || $options[$key] === null || $options[$key] === '') {
            $this->addIssue($key.'_required');
        }
    }

    private function optionalInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array<string, mixed> $options */
    private function optionString(array $options, string $key): ?string
    {
        return isset($options[$key]) && trim((string) $options[$key]) !== '' ? trim((string) $options[$key]) : null;
    }

    private function hashMatches(mixed $expected, mixed $actual): bool
    {
        return $expected !== null && (string) $expected !== '' && $actual !== null && hash_equals((string) $expected, (string) $actual);
    }

    private function boundedLimit(mixed $value): int
    {
        return max(0, min((int) $value, 100));
    }

    /** @param array<string, mixed> $audit */
    private function mapAuditFailure(array $audit): string
    {
        $reason = (string) data_get($audit, 'issues.0.reason', 'audit_failed');

        return match (true) {
            $reason === 'remote_source_disabled' => 'remote_source_refused',
            str_ends_with($reason, '_file_missing'), str_ends_with($reason, '_required') => 'source_missing',
            $reason === 'parse_error' => 'malformed_xml',
            default => $reason !== '' ? $reason : 'audit_failed',
        };
    }

    /** @param array<string, mixed> $context */
    private function addIssue(string $reason, array $context = []): void
    {
        $this->issueCounts[$reason] = ($this->issueCounts[$reason] ?? 0) + 1;
        if (count($this->issueSamples) < $this->issueSampleLimit) {
            $sample = ['reason' => $reason];
            foreach (['sku_hash', 'fields', 'table', 'sample'] as $key) {
                if (array_key_exists($key, $context)) {
                    $sample[$key] = $context[$key];
                }
            }
            $this->issueSamples[] = $sample;
        }
    }

    private function verdict(): string
    {
        if ($this->issueCounts === []) {
            return 'verified';
        }
        foreach (array_keys($this->issueCounts) as $reason) {
            if (str_contains($reason, 'fingerprint') || str_contains($reason, 'source_') || in_array($reason, ['malformed_xml', 'remote_source_refused', 'source_missing'], true)) {
                return 'source_mismatch';
            }
            if (str_contains($reason, 'candidate') || str_contains($reason, 'sku') || str_contains($reason, 'row_') || str_contains($reason, 'provenance') || str_contains($reason, 'truncation') || str_contains($reason, 'payload_schema') || str_contains($reason, 'availability') || str_contains($reason, 'price') || str_contains($reason, 'currency')) {
                return 'candidate_mismatch';
            }
            if (str_contains($reason, 'database') || str_contains($reason, 'staged')) {
                return 'database_mismatch';
            }
            if (str_contains($reason, 'flag') || str_contains($reason, 'schedule') || str_contains($reason, 'supplier_must')) {
                return 'unsafe_configuration';
            }
        }

        return 'verification_failed';
    }
}
