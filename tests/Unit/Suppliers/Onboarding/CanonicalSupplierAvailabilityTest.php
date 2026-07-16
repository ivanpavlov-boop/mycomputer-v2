<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Contracts\Suppliers\Onboarding\SupplierAvailabilityMapper;
use App\Enums\Suppliers\Onboarding\CanonicalPublicAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierAvailabilityStatus;
use App\Enums\Suppliers\Onboarding\CanonicalSupplierLifecycleStatus;
use App\Services\Suppliers\Onboarding\ApcomAvailabilityMapper;
use PHPUnit\Framework\TestCase;

final class CanonicalSupplierAvailabilityTest extends TestCase
{
    public function test_supplier_neutral_canonical_status_values_are_stable_and_deterministic(): void
    {
        $this->assertSame(['in_stock', 'limited', 'on_request', 'out_of_stock', 'unknown'], array_column(CanonicalSupplierAvailabilityStatus::cases(), 'value'));
        $this->assertSame(['active', 'eol', 'discontinued', 'unknown'], array_column(CanonicalSupplierLifecycleStatus::cases(), 'value'));
        $this->assertSame(['in_stock', 'limited', 'on_request', 'last_units', 'unavailable', 'discontinued', 'unknown'], array_column(CanonicalPublicAvailabilityStatus::cases(), 'value'));

        $first = array_map(static fn (CanonicalPublicAvailabilityStatus $status): string => $status->value, CanonicalPublicAvailabilityStatus::cases());
        $second = array_map(static fn (CanonicalPublicAvailabilityStatus $status): string => $status->value, CanonicalPublicAvailabilityStatus::cases());
        $this->assertSame($first, $second);
    }

    public function test_apcom_mapping_is_a_supplier_specific_implementation_of_the_neutral_contract(): void
    {
        $mapper = new ApcomAvailabilityMapper;

        $this->assertInstanceOf(SupplierAvailabilityMapper::class, $mapper);
        $this->assertSame('apcom', $mapper->supplierKey());
        $this->assertSame('apcom-availability-policy-v1', $mapper->policy()['policy_key']);
    }
}
