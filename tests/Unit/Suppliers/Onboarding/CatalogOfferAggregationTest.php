<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\CatalogOfferAggregationInput;
use App\Services\Suppliers\Onboarding\CatalogOfferAggregationPolicy;
use PHPUnit\Framework\TestCase;

final class CatalogOfferAggregationTest extends TestCase
{
    public function test_one_inactive_supplier_offer_cannot_unpublish_a_product_with_an_active_offer(): void
    {
        $result = $this->preview([
            ['canonical_public_status' => 'unavailable', 'valid' => true],
            ['canonical_public_status' => 'in_stock', 'valid' => true],
        ]);

        $this->assertSame(1, $result['valid_active_offer_count']);
        $this->assertSame('in_stock', $result['selected_canonical_public_status']);
        $this->assertTrue($result['product_should_be_sellable']);
        $this->assertFalse($result['product_visibility_transition_eligible']);
        $this->assertFalse($result['write_allowed']);
    }

    public function test_on_request_and_eol_positive_stock_remain_commercially_active(): void
    {
        $onRequest = $this->preview([
            ['canonical_public_status' => 'on_request', 'valid' => true],
            ['canonical_public_status' => 'unavailable', 'valid' => true],
        ]);
        $lastUnits = $this->preview([
            ['canonical_public_status' => 'last_units', 'valid' => true],
        ]);

        $this->assertSame('on_request', $onRequest['selected_canonical_public_status']);
        $this->assertTrue($onRequest['has_active_commercial_offer']);
        $this->assertSame('last_units', $lastUnits['selected_canonical_public_status']);
        $this->assertTrue($lastUnits['product_should_be_sellable']);
    }

    public function test_invalid_offer_does_not_override_a_valid_active_offer_and_all_inactive_offers_transition(): void
    {
        $validWins = $this->preview([
            ['canonical_public_status' => 'unknown', 'valid' => false, 'blocked' => true],
            ['canonical_public_status' => 'limited', 'valid' => true],
        ]);
        $allInactive = $this->preview([
            ['canonical_public_status' => 'unavailable', 'valid' => true],
            ['canonical_public_status' => 'discontinued', 'valid' => true],
        ]);

        $this->assertSame(1, $validWins['blocked_offer_count']);
        $this->assertSame('limited', $validWins['selected_canonical_public_status']);
        $this->assertFalse($allInactive['has_active_commercial_offer']);
        $this->assertTrue($allInactive['product_visibility_transition_eligible']);
        $this->assertSame(0, $allInactive['records_changed']);
    }

    /** @param array<int, array{canonical_public_status: string, valid: bool, blocked?: bool}> $offers @return array<string, mixed> */
    private function preview(array $offers): array
    {
        return (new CatalogOfferAggregationPolicy)->preview(new CatalogOfferAggregationInput('synthetic-product', $offers))->toArray();
    }
}
