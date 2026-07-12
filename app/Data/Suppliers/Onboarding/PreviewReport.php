<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class PreviewReport implements JsonSerializable
{
    public const REPORT_SCHEMA_VERSION = 'supplier-preview-report-v1';

    /** @var array<string, int> */
    public readonly array $classificationCounts;

    /** @var array<string, int> */
    public readonly array $issueCounts;

    /** @var array<int, array<string, mixed>> */
    public readonly array $issueSamples;

    /** @var array<int, array<string, mixed>> */
    public readonly array $sourceFingerprints;

    /** @var array<string, int> */
    public readonly array $mutationCounters;

    public function __construct(
        public int $totalSourceRecords,
        public int $normalizedRecordCount,
        public int $validRecordCount,
        public int $rejectedRecordCount,
        array $classificationCounts = [],
        array $issueCounts = [],
        array $issueSamples = [],
        array $sourceFingerprints = [],
        public ?string $candidateFingerprint = null,
        public string $profileVersion = 'unknown',
        public string $driverVersion = 'unknown',
        array $mutationCounters = [],
    ) {
        if ($totalSourceRecords < 0 || $normalizedRecordCount < 0 || $validRecordCount < 0 || $rejectedRecordCount < 0) {
            throw new InvalidArgumentException('Preview report counts cannot be negative.');
        }

        if ($normalizedRecordCount !== $validRecordCount + $rejectedRecordCount) {
            throw new InvalidArgumentException('Preview report record counts do not reconcile.');
        }

        $this->classificationCounts = self::classificationCounts($classificationCounts);
        $this->issueCounts = self::integerMap($issueCounts);
        $this->issueSamples = self::safeBoundedList($issueSamples, 20, 'preview issue samples');
        $this->sourceFingerprints = array_map(
            static fn (mixed $fingerprint): array => $fingerprint instanceof SourceFingerprint
                ? $fingerprint->toArray()
                : (array) $fingerprint,
            self::boundedList($sourceFingerprints, 50)
        );
        $this->mutationCounters = self::mutationCounters($mutationCounters);

        if (array_sum($this->classificationCounts) !== $normalizedRecordCount) {
            throw new InvalidArgumentException('Preview classification counts do not reconcile.');
        }

        if (array_filter($this->mutationCounters) !== []) {
            throw new InvalidArgumentException('Preview reports cannot contain mutations.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'total_source_records' => $this->totalSourceRecords,
            'normalized_record_count' => $this->normalizedRecordCount,
            'valid_record_count' => $this->validRecordCount,
            'rejected_record_count' => $this->rejectedRecordCount,
            'classification_counts' => $this->classificationCounts,
            'issue_counts' => $this->issueCounts,
            'issue_samples' => $this->issueSamples,
            'source_fingerprints' => $this->sourceFingerprints,
            'candidate_fingerprint' => $this->candidateFingerprint,
            'profile_version' => $this->profileVersion,
            'driver_version' => $this->driverVersion,
            'read_only' => true,
            'products_changed' => 0,
            'supplier_products_changed' => 0,
            'catalog_sync_called' => false,
            'jobs_dispatched' => 0,
            'schedule_changes' => 0,
            'images_imported' => 0,
            'mutation_counters' => $this->mutationCounters,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, int> */
    private static function classificationCounts(array $counts): array
    {
        $normalized = [];

        foreach (PreviewClassification::cases() as $classification) {
            $value = $counts[$classification->value] ?? 0;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Preview classification counts must be non-negative integers.');
            }

            $normalized[$classification->value] = $value;
        }

        return $normalized;
    }

    /** @return array<string, int> */
    private static function integerMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Preview issue counts must be non-negative integers.');
            }

            $normalized[(string) $key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @return array<int, mixed> */
    private static function boundedList(array $values, int $limit): array
    {
        return array_slice(array_values($values), 0, $limit);
    }

    /** @return array<int, mixed> */
    private static function safeBoundedList(array $values, int $limit, string $context): array
    {
        $bounded = self::boundedList($values, $limit);
        OnboardingValueGuard::assertSafe($bounded, $context);

        return $bounded;
    }

    /** @return array<string, int> */
    private static function mutationCounters(array $values): array
    {
        $keys = [
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
            'catalog_sync',
        ];

        $normalized = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? 0;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Preview mutation counters must be non-negative integers.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
