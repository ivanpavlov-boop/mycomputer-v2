<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierOfferReappearanceInput;
use App\Data\Suppliers\Onboarding\SupplierOfferReappearancePreviewResult;
use App\Data\Suppliers\Onboarding\SupplierSnapshotQualificationInput;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferLifecyclePreviewService;
use App\Services\Suppliers\Onboarding\SupplierOfferLifecyclePreviewService;
use App\Services\Suppliers\Onboarding\SupplierOfferReappearancePolicy;
use App\Services\Suppliers\Onboarding\SupplierSnapshotQualificationPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SupplierOfferReappearanceTest extends TestCase
{
    public function test_valid_qualified_reappearance_resets_the_lifecycle_without_a_write(): void
    {
        $result = $this->preview(price: '120')->toArray();

        $this->assertSame('reappeared', $result['next_presence_status']);
        $this->assertSame(0, $result['consecutive_missing_count']);
        $this->assertNull($result['first_missing_at']);
        $this->assertTrue($result['reactivation_eligible']);
        $this->assertTrue($result['would_reactivate_supplier_offer']);
        $this->assertFalse($result['write_allowed']);
        $this->assertSame(0, $result['records_changed']);
    }

    public function test_zero_price_and_identifier_conflict_reappearance_are_blocked(): void
    {
        $zeroPrice = $this->preview(price: '0')->toArray();
        $conflict = $this->preview(price: '120', identifierConflict: true)->toArray();

        $this->assertSame('review_only', $zeroPrice['next_presence_status']);
        $this->assertContains('zero_or_invalid_price', $zeroPrice['reason_codes']);
        $this->assertFalse($zeroPrice['reactivation_eligible']);
        $this->assertSame('blocked', $conflict['next_presence_status']);
        $this->assertContains('identifier_conflict', $conflict['reason_codes']);
        $this->assertFalse($conflict['would_reactivate_supplier_offer']);
    }

    public function test_exact_decimal_price_boundaries_are_not_converted_to_float(): void
    {
        foreach (['0.01', '9999999999.99'] as $price) {
            $result = $this->preview($price)->toArray();
            $this->assertTrue($result['reactivation_eligible']);
            $this->assertSame('reappeared', $result['next_presence_status']);
        }

        foreach (['10000000000', '10000000000.00', '1.001', '0.001', '1e2', '9999999999.990'] as $price) {
            try {
                $this->preview($price);
                $this->fail("Expected exact price rejection for [{$price}].");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('invalid_offer_price', $exception->getMessage());
            }
        }
    }

    public function test_reachable_reappearance_price_decisions_use_nullable_strings_without_float_casts(): void
    {
        $constructor = (new ReflectionClass(SupplierOfferReappearanceInput::class))->getConstructor();
        $price = collect($constructor?->getParameters())
            ->first(static fn (\ReflectionParameter $parameter): bool => $parameter->getName() === 'price');

        $this->assertNotNull($price);
        $this->assertSame('string', $price->getType()?->getName());
        $this->assertTrue($price->allowsNull());

        foreach ([
            SupplierOfferReappearanceInput::class,
            SupplierOfferReappearancePolicy::class,
            SupplierOfferLifecyclePreviewService::class,
            OperationalSupplierOfferLifecyclePreviewService::class,
        ] as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression('/\(\s*float\s*\)|\??float\s+\$price\b|price\s*:\s*\(\s*float\s*\)/i', $source, $class);
        }
    }

    private function preview(?string $price, bool $identifierConflict = false): SupplierOfferReappearancePreviewResult
    {
        $at = CarbonImmutable::parse('2026-07-17 12:00:00', 'UTC');
        $qualification = (new SupplierSnapshotQualificationPolicy)->qualify(new SupplierSnapshotQualificationInput(
            supplierKey: 'apcom', snapshotId: 'synthetic-snapshot', snapshotStatus: 'completed', observedAt: $at,
            isSuccessful: true, isFullSnapshot: true, isSchemaValid: true, isTruncated: false, productCount: 100,
            minimumProductCount: 100, productDropPercent: '0', maximumProductDropPercent: '50', hasFatalBlocker: false,
            supplierIdentityConfirmed: true, snapshotFingerprint: 'synthetic-fingerprint', isDuplicateFingerprint: false,
        ));

        return (new SupplierOfferReappearancePolicy)->preview(new SupplierOfferReappearanceInput(
            supplierKey: 'apcom', supplierSkuHash: 'synthetic-offer', previousPresenceStatus: 'inactive_missing_from_feed',
            evaluatedAt: $at, supplierSkuMatchesExactly: true, price: $price, supplierMapperValid: true,
            hasIdentifierConflict: $identifierConflict, hasBlockingValidationIssue: false,
        ), $qualification);
    }
}
