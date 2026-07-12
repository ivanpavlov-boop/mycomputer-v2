<?php

namespace App\Data\Suppliers\Onboarding;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SupplierReadinessMatrixReport implements JsonSerializable
{
    public const SCHEMA_VERSION = 'supplier-readiness-matrix-v1';

    /** @var array<string, bool> */
    public array $globalSafetyFlags;

    /** @var array<string, int> */
    public array $readinessStageCounts;

    /** @var array<string, int> */
    public array $blockerCounts;

    /** @var array<string, int> */
    public array $warningCounts;

    /** @var array<int, SupplierReadinessMatrixRow> */
    public array $suppliers;

    /** @var array<string, int> */
    public array $recordsChanged;

    /** @var array<int, ValidationIssue> */
    public array $issues;

    /**
     * @param  array<string, bool>  $globalSafetyFlags
     * @param  array<string, int>  $readinessStageCounts
     * @param  array<string, int>  $blockerCounts
     * @param  array<string, int>  $warningCounts
     * @param  array<int, SupplierReadinessMatrixRow>  $suppliers
     * @param  array<string, int>  $recordsChanged
     * @param  array<int, ValidationIssue>  $issues
     */
    public function __construct(
        public string $generatedAt,
        public int $supplierCount,
        public int $activeSupplierCount,
        public int $disabledSupplierCount,
        public int $importEnabledCount,
        public int $scheduleEnabledCount,
        public int $sourceConfiguredCount,
        public int $authenticationConfiguredCount,
        public int $driverAvailableCount,
        public int $profileAvailableCount,
        public int $previewCapableCount,
        public int $controlledStagingCapableCount,
        public int $postApplyVerificationCapableCount,
        public int $suppliersWithStagingCount,
        public int $suppliersWithLinkedProductsCount,
        public int $totalStagingRows,
        public int $totalLinkedStagingRows,
        array $globalSafetyFlags,
        array $readinessStageCounts,
        array $blockerCounts,
        array $warningCounts,
        array $suppliers,
        array $recordsChanged,
        array $issues,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
        public ReadinessVerdict $matrixVerdict,
    ) {
        foreach ([
            'supplier count' => $supplierCount,
            'active supplier count' => $activeSupplierCount,
            'disabled supplier count' => $disabledSupplierCount,
            'import enabled count' => $importEnabledCount,
            'schedule enabled count' => $scheduleEnabledCount,
            'source configured count' => $sourceConfiguredCount,
            'authentication configured count' => $authenticationConfiguredCount,
            'driver available count' => $driverAvailableCount,
            'profile available count' => $profileAvailableCount,
            'preview capable count' => $previewCapableCount,
            'controlled staging capable count' => $controlledStagingCapableCount,
            'post-apply verification capable count' => $postApplyVerificationCapableCount,
            'suppliers with staging count' => $suppliersWithStagingCount,
            'suppliers with linked products count' => $suppliersWithLinkedProductsCount,
            'total staging rows' => $totalStagingRows,
            'total linked staging rows' => $totalLinkedStagingRows,
            'peak memory bytes' => $peakMemoryBytes,
        ] as $label => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$label} cannot be negative.");
            }
        }

        if ($supplierCount !== count($suppliers)) {
            throw new InvalidArgumentException('Supplier count must match matrix rows.');
        }

        foreach ($suppliers as $supplier) {
            if (! $supplier instanceof SupplierReadinessMatrixRow) {
                throw new InvalidArgumentException('Matrix report suppliers must be readiness rows.');
            }
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof ValidationIssue) {
                throw new InvalidArgumentException('Matrix report issues must be validation issues.');
            }
        }

        $this->globalSafetyFlags = self::boolMap($globalSafetyFlags);
        $this->readinessStageCounts = self::integerMap($readinessStageCounts);
        $this->blockerCounts = self::integerMap($blockerCounts);
        $this->warningCounts = self::integerMap($warningCounts);
        $this->suppliers = array_values($suppliers);
        $this->recordsChanged = self::recordsChanged($recordsChanged);
        $this->issues = array_slice(array_values($issues), 0, 20);
    }

    public function isSafeConfiguration(): bool
    {
        return $this->matrixVerdict !== ReadinessVerdict::UNSAFE_CONFIGURATION
            && $this->matrixVerdict !== ReadinessVerdict::AUDIT_FAILED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return CanonicalOnboardingData::normalize([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'multi_supplier_readiness_matrix',
            'read_only' => true,
            'generated_at' => $this->generatedAt,
            'supplier_count' => $this->supplierCount,
            'active_supplier_count' => $this->activeSupplierCount,
            'disabled_supplier_count' => $this->disabledSupplierCount,
            'import_enabled_count' => $this->importEnabledCount,
            'schedule_enabled_count' => $this->scheduleEnabledCount,
            'source_configured_count' => $this->sourceConfiguredCount,
            'authentication_configured_count' => $this->authenticationConfiguredCount,
            'driver_available_count' => $this->driverAvailableCount,
            'profile_available_count' => $this->profileAvailableCount,
            'preview_capable_count' => $this->previewCapableCount,
            'controlled_staging_capable_count' => $this->controlledStagingCapableCount,
            'post_apply_verification_capable_count' => $this->postApplyVerificationCapableCount,
            'suppliers_with_staging_count' => $this->suppliersWithStagingCount,
            'suppliers_with_linked_products_count' => $this->suppliersWithLinkedProductsCount,
            'total_staging_rows' => $this->totalStagingRows,
            'total_linked_staging_rows' => $this->totalLinkedStagingRows,
            'global_safety_flags' => $this->globalSafetyFlags,
            'readiness_stage_counts' => $this->readinessStageCounts,
            'blocker_counts' => $this->blockerCounts,
            'warning_counts' => $this->warningCounts,
            'suppliers' => $this->suppliers,
            'records_changed' => $this->recordsChanged,
            'issues' => $this->issues,
            'elapsed_seconds' => $this->elapsedSeconds,
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'matrix_verdict' => $this->matrixVerdict->value,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, bool> */
    private static function boolMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_bool($value)) {
                throw new InvalidArgumentException('Matrix safety flags must be booleans.');
            }

            $normalized[(string) $key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @return array<string, int> */
    private static function integerMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Matrix counters must be non-negative integers.');
            }

            $normalized[(string) $key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @return array<string, int> */
    private static function recordsChanged(array $values): array
    {
        $tables = [
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
            'catalog_sync',
        ];

        $normalized = [];

        foreach ($tables as $table) {
            $value = $values[$table] ?? 0;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Matrix change counters must be non-negative integers.');
            }

            $normalized[$table] = $value;
        }

        return $normalized;
    }
}
