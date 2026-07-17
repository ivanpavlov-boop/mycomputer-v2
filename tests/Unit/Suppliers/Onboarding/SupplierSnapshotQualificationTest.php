<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationResult;
use App\Services\Suppliers\Onboarding\SupplierSnapshotQualificationPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SupplierSnapshotQualificationTest extends TestCase
{
    public function test_successful_full_snapshot_qualifies(): void
    {
        $result = $this->qualify()->toArray();

        $this->assertTrue($result['qualifies_for_presence_tracking']);
        $this->assertSame([], $result['freeze_reason_codes']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(0, $result['records_changed']);
    }

    public function test_failed_partial_malformed_and_truncated_snapshots_freeze_tracking(): void
    {
        foreach ([
            ['isSuccessful' => false, 'expected' => 'snapshot_not_successful'],
            ['isFullSnapshot' => false, 'expected' => 'snapshot_not_full'],
            ['isSchemaValid' => false, 'expected' => 'snapshot_schema_invalid'],
            ['isTruncated' => true, 'expected' => 'snapshot_truncated'],
        ] as $case) {
            $result = $this->qualify(...$case)->toArray();

            $this->assertFalse($result['qualifies_for_presence_tracking']);
            $this->assertContains($case['expected'], $result['freeze_reason_codes']);
            $this->assertTrue($result['requires_human_review']);
        }
    }

    public function test_anomalous_drop_minimum_count_and_duplicate_fingerprint_freeze_tracking(): void
    {
        $drop = $this->qualify(productDropPercent: 51.0)->toArray();
        $minimum = $this->qualify(productCount: 99)->toArray();
        $duplicate = $this->qualify(isDuplicateFingerprint: true)->toArray();

        $this->assertContains('maximum_product_drop_exceeded', $drop['freeze_reason_codes']);
        $this->assertContains('minimum_product_count_not_met', $minimum['freeze_reason_codes']);
        $this->assertContains('duplicate_snapshot_fingerprint', $duplicate['freeze_reason_codes']);
        $this->assertFalse($drop['qualifies_for_presence_tracking']);
        $this->assertFalse($minimum['qualifies_for_presence_tracking']);
        $this->assertFalse($duplicate['qualifies_for_presence_tracking']);
    }

    private function qualify(
        bool $isSuccessful = true,
        bool $isFullSnapshot = true,
        bool $isSchemaValid = true,
        bool $isTruncated = false,
        int $productCount = 100,
        float $productDropPercent = 5.0,
        bool $isDuplicateFingerprint = false,
        ?string $expected = null,
    ): SupplierSnapshotQualificationResult {
        return (new SupplierSnapshotQualificationPolicy)->qualify(new SupplierSnapshotQualificationInput(
            supplierKey: 'apcom',
            snapshotId: 'synthetic-snapshot',
            snapshotStatus: $isSuccessful ? 'completed' : 'failed',
            observedAt: CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC'),
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
            snapshotFingerprint: 'synthetic-fingerprint',
            isDuplicateFingerprint: $isDuplicateFingerprint,
        ));
    }
}
