<?php

namespace Tests\Unit\Suppliers\Onboarding;

use App\Services\Suppliers\Onboarding\SupplierSourceFieldSemanticsRegistry;
use Tests\TestCase;

class SupplierSourceFieldSemanticsProfileTest extends TestCase
{
    public function test_apcom_official_profile_exposes_only_confirmed_semantics_and_explicit_unknowns(): void
    {
        $profile = app(SupplierSourceFieldSemanticsRegistry::class)->find('apcom-official-v1');

        $this->assertNotNull($profile);
        $this->assertSame('apcom', $profile->supplierKey);
        $this->assertSame('xml.product', $profile->recordPath);
        $this->assertSame('partno', $profile->fieldMap['supplier_sku']);
        $this->assertSame('ean', $profile->fieldMap['ean']);
        $this->assertSame(['dac_price', 'fd_price'], $profile->fieldMap['price_candidates']);
        $this->assertNull($profile->fieldMap['mpn']);
        $this->assertNull($profile->fieldMap['quantity']);
        $this->assertNull($profile->fieldMap['currency']);
        $this->assertNull($profile->fieldMap['vat']);
        $this->assertNull($profile->fieldMap['selected_price']);
        $this->assertTrue($profile->toArray()['stock_is_not_quantity']);
        $this->assertTrue($profile->toArray()['previous_quantity_to_stock_heuristic_superseded']);
        $this->assertTrue($profile->toArray()['partno_is_not_mpn']);
        $this->assertTrue($profile->toArray()['cncode_is_not_identifier']);
        $this->assertFalse($profile->toArray()['semantics_profile_persisted']);
    }

    public function test_apcom_observed_stock_profile_keeps_numeric_stock_unresolved_and_non_persistent(): void
    {
        $profile = app(SupplierSourceFieldSemanticsRegistry::class)->find('apcom-observed-stock-v1');

        $this->assertNotNull($profile);
        $this->assertSame('apcom', $profile->supplierKey);
        $this->assertSame('xml.product', $profile->recordPath);
        $this->assertSame('partno', $profile->fieldMap['supplier_sku']);
        $this->assertSame('stock', $profile->fieldMap['observed_stock']);
        $this->assertNull($profile->fieldMap['quantity']);
        $this->assertNull($profile->fieldMap['availability']);
        $this->assertSame('eol', $profile->fieldMap['lifecycle_eol']);
        $this->assertTrue($profile->usesObservedNumericStockContract());

        $payload = $profile->toArray();
        $this->assertSame('non_negative_integer_numeric', $payload['observed_stock_contract']);
        $this->assertTrue($payload['semantics_discrepancy']);
        $this->assertSame('unresolved', $payload['semantic_resolution']);
        $this->assertTrue($payload['stock_is_not_quantity']);
        $this->assertTrue($payload['stock_is_not_binary_availability']);
        $this->assertFalse($payload['automatic_quantity_mapping_allowed']);
        $this->assertFalse($payload['automatic_availability_mapping_allowed']);
        $this->assertFalse($payload['semantics_profile_persisted']);
    }
}
