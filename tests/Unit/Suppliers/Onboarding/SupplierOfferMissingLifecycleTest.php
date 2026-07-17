<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferPresenceObservation;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Services\Suppliers\Onboarding\SupplierOfferLifecyclePolicy;
use App\Services\Suppliers\Onboarding\SupplierSnapshotQualificationPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SupplierOfferMissingLifecycleTest extends TestCase
{
    public function test_missing_offer_requires_three_qualified_snapshots_and_48_hours(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');

        $first = $this->missing('present', 0, null, $start);
        $second = $this->missing('missing_once', 1, $start, $start->addHour());
        $thirdEarly = $this->missing('missing_repeatedly', 2, $start, $start->addHours(47));
        $thirdAtThreshold = $this->missing('missing_repeatedly', 2, $start, $start->addHours(48));

        $this->assertSame('missing_once', $first['next_presence_status']);
        $this->assertSame('missing_repeatedly', $second['next_presence_status']);
        $this->assertSame('missing_threshold_reached_waiting_duration', $thirdEarly['next_presence_status']);
        $this->assertFalse($thirdEarly['deactivation_eligible']);
        $this->assertSame('inactive_missing_from_feed', $thirdAtThreshold['next_presence_status']);
        $this->assertTrue($thirdAtThreshold['deactivation_eligible']);
        $this->assertTrue($thirdAtThreshold['would_deactivate_supplier_offer']);
        $this->assertFalse($thirdAtThreshold['write_allowed']);
        $this->assertSame(0, $thirdAtThreshold['records_changed']);
    }

    public function test_presence_resets_missing_state_and_frozen_snapshot_does_not_increment_it(): void
    {
        $start = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $present = $this->observe('missing_repeatedly', 2, $start, $start->addHours(3), true, true);
        $frozen = $this->observe('missing_once', 1, $start, $start->addHours(3), false, false);

        $this->assertSame('present', $present['next_presence_status']);
        $this->assertSame(0, $present['consecutive_missing_count']);
        $this->assertNull($present['first_missing_at']);
        $this->assertSame('missing_once', $frozen['next_presence_status']);
        $this->assertSame(1, $frozen['consecutive_missing_count']);
        $this->assertContains('snapshot_not_successful', $frozen['freeze_reason_codes']);
        $this->assertFalse($frozen['deactivation_eligible']);
    }

    public function test_absence_never_maps_to_eol_or_catalog_write(): void
    {
        $result = $this->missing('present', 0, null, CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'));

        $this->assertSame('missing_once', $result['next_presence_status']);
        $this->assertFalse($result['write_allowed']);
        $this->assertFalse($result['would_deactivate_supplier_offer']);
    }

    /** @return array<string, mixed> */
    private function missing(string $status, int $count, ?CarbonImmutable $firstMissingAt, CarbonImmutable $evaluatedAt): array
    {
        return $this->observe($status, $count, $firstMissingAt, $evaluatedAt, false, true);
    }

    /** @return array<string, mixed> */
    private function observe(string $status, int $count, ?CarbonImmutable $firstMissingAt, CarbonImmutable $evaluatedAt, bool $present, bool $successful): array
    {
        $qualification = (new SupplierSnapshotQualificationPolicy)->qualify(new SupplierSnapshotQualificationInput(
            supplierKey: 'apcom', snapshotId: 'synthetic-snapshot', snapshotStatus: $successful ? 'completed' : 'failed', observedAt: $evaluatedAt,
            isSuccessful: $successful, isFullSnapshot: true, isSchemaValid: true, isTruncated: false, productCount: 100,
            minimumProductCount: 100, productDropPercent: 0.0, maximumProductDropPercent: 50.0, hasFatalBlocker: false,
            supplierIdentityConfirmed: true, snapshotFingerprint: 'synthetic-fingerprint', isDuplicateFingerprint: false,
        ));

        return (new SupplierOfferLifecyclePolicy)->preview(new SupplierOfferPresenceObservation(
            supplierKey: 'apcom', supplierSkuHash: 'synthetic-offer', previousPresenceStatus: $status,
            previousConsecutiveMissingCount: $count, previousFirstMissingAt: $firstMissingAt, evaluatedAt: $evaluatedAt,
            isPresentInSnapshot: $present,
        ), $qualification)->toArray();
    }
}
