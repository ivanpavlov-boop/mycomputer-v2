<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Services\Suppliers\Onboarding\ApcomAvailabilityMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApcomAvailabilityPolicyTest extends TestCase
{
    public function test_apcom_maps_all_approved_stock_and_lifecycle_examples_without_public_exact_quantity(): void
    {
        $mapper = new ApcomAvailabilityMapper;

        foreach ([
            [0, 0, 'on_request', 'active', 'on_request', false, null, true],
            [1, 0, 'limited', 'active', 'limited', false, null, false],
            [5, 0, 'limited', 'active', 'limited', false, null, false],
            [6, 0, 'in_stock', 'active', 'in_stock', false, null, false],
            [40, 0, 'in_stock', 'active', 'in_stock', false, null, false],
            [100, 0, 'in_stock', 'active', 'in_stock', true, 100, false],
            [3, 1, 'limited', 'eol', 'last_units', false, null, false],
            [100, 1, 'in_stock', 'eol', 'last_units', true, 100, false],
            [0, 1, 'out_of_stock', 'eol', 'discontinued', false, null, false],
        ] as [$stock, $eol, $availability, $lifecycle, $public, $capped, $minimum, $confirmation]) {
            $result = $mapper->map($stock, $eol)->toArray();

            $this->assertSame($availability, $result['canonical_availability_status']);
            $this->assertSame($lifecycle, $result['canonical_lifecycle_status']);
            $this->assertSame($public, $result['canonical_public_status']);
            $this->assertSame($capped, $result['quantity_is_capped']);
            $this->assertSame($minimum, $result['quantity_minimum']);
            $this->assertSame($confirmation, $result['requires_availability_confirmation']);
            $this->assertFalse($result['exact_public_quantity_allowed']);
            $this->assertFalse($result['automatic_execution_allowed']);
            $this->assertFalse($result['catalog_write_allowed']);
            $this->assertFalse($result['staging_write_allowed']);
        }
    }

    public function test_apcom_policy_uses_a_reusable_threshold_and_rejects_invalid_inputs(): void
    {
        $mapper = new ApcomAvailabilityMapper;

        $this->assertSame(5, $mapper->policy()['low_stock_threshold']);
        $this->expectException(InvalidArgumentException::class);
        $mapper->map(-1, 0);
    }

    public function test_apcom_policy_rejects_non_contract_cap_and_invalid_eol(): void
    {
        $mapper = new ApcomAvailabilityMapper;

        try {
            $mapper->map(101, 0);
            $this->fail('Expected capped stock validation to fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $mapper->map(1, 2);
    }

    public function test_apcom_policy_rejects_non_integer_stock(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ApcomAvailabilityMapper)->map('1.5', 0);
    }
}
