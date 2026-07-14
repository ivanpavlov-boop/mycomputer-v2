<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Services\Suppliers\Onboarding\SupplierSourceFieldSemanticsRegistry;
use Tests\TestCase;

class ObservedStockSemanticsTest extends TestCase
{
    public function test_observed_profile_is_non_persistent_and_does_not_approve_stock_mappings(): void
    {
        $profile = app(SupplierSourceFieldSemanticsRegistry::class)->find('apcom-observed-stock-v1');

        $this->assertSame('apcom-observed-stock-v1', $profile->key);
        $this->assertSame('stock', $profile->fieldMap['observed_stock']);
        $this->assertNull($profile->fieldMap['quantity']);
        $this->assertNull($profile->fieldMap['availability']);
        $this->assertSame('unresolved_numeric', $profile->stockSemantics['observed_stock_semantic_status']);
        $this->assertSame('non_negative_integer_numeric', $profile->stockSemantics['observed_stock_contract']);
        $this->assertTrue($profile->stockSemantics['semantics_discrepancy']);
        $this->assertSame('unresolved', $profile->stockSemantics['semantic_resolution']);
        $this->assertFalse($profile->stockSemantics['automatic_quantity_mapping_allowed']);
        $this->assertFalse($profile->stockSemantics['automatic_availability_mapping_allowed']);
        $this->assertTrue($profile->toArray()['requires_human_review']);
    }
}
