<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegisterValidator;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use Tests\TestCase;

final class AuthoritativeHumanDecisionsV2Test extends TestCase
{
    public function test_v1_register_remains_unchanged_while_v2_explicitly_supersedes_it(): void
    {
        $v1 = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v1');
        $v2 = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v2');

        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertSame('apcom-human-decisions-v1', $v1->toArray()['key']);
        $this->assertArrayNotHasKey('supersedes_key', $v1->toArray());
        $this->assertSame('apcom-human-decisions-v1', $v2->toArray()['supersedes_key']);
        $this->assertSame('apcom-human-decisions-v2', $v2->toArray()['key']);
        $this->assertTrue(app(SupplierHumanDecisionRegisterValidator::class)->validate($v2)['valid']);
    }

    public function test_v2_records_confirmed_business_semantics_without_execution_approval(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v2');
        $decisions = collect($register->toArray()['decisions'])->keyBy('decision_id');

        foreach (['APCOM-STOCK-001', 'APCOM-AVAILABILITY-001', 'APCOM-LIFECYCLE-001', 'APCOM-PRICE-001', 'APCOM-CURRENCY-001', 'APCOM-VAT-001', 'APCOM-GREEN-TAX-001'] as $id) {
            $this->assertSame('confirmed', $decisions[$id]['status']);
            $this->assertFalse($decisions[$id]['automatic_execution_allowed']);
            $this->assertFalse($decisions[$id]['catalog_write_allowed']);
            $this->assertFalse($decisions[$id]['staging_write_allowed']);
        }

        $this->assertSame('supplier_available_quantity_snapshot', $decisions['APCOM-STOCK-001']['approved_role']);
        $this->assertSame('apcom-availability-policy-v1', $decisions['APCOM-AVAILABILITY-001']['approved_role']);
        $this->assertSame('supplier_purchase_price', $decisions['APCOM-PRICE-001']['approved_role']);
        $this->assertSame('EUR', $decisions['APCOM-CURRENCY-001']['approved_role']);
        $this->assertSame('exclusive', $decisions['APCOM-VAT-001']['approved_role']);
        $this->assertSame('included_in_fd_price', $decisions['APCOM-GREEN-TAX-001']['approved_role']);
    }

    public function test_v2_keeps_mpn_missing_product_and_destructive_actions_unapproved(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v2');
        $decisions = collect($register->toArray()['decisions'])->keyBy('decision_id');

        foreach (['APCOM-MPN-001', 'APCOM-SOURCE-ONLY-001', 'APCOM-STAGING-ONLY-001', 'APCOM-LINKED-STAGING-ONLY-001'] as $id) {
            $this->assertSame('pending', $decisions[$id]['status']);
        }
        foreach (['APCOM-QUANTITY-001', 'APCOM-EOL-REVIEW-001', 'APCOM-ZERO-PRICE-001'] as $id) {
            $this->assertSame('review_only', $decisions[$id]['status']);
        }
        foreach (['APCOM-PROHIBIT-AUTO-IMPORT-001', 'APCOM-PROHIBIT-SCHEDULE-001', 'APCOM-PROHIBIT-SYNC-ALL-001', 'APCOM-PROHIBIT-AUTO-SYNC-001', 'APCOM-PROHIBIT-UPDATE-SYNC-001', 'APCOM-PROHIBIT-IMAGE-IMPORT-001', 'APCOM-PROHIBIT-CONTENT-OVERWRITE-001'] as $id) {
            $this->assertSame('prohibited', $decisions[$id]['status']);
        }
    }
}
