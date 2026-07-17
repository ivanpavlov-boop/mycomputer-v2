<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegisterValidator;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignValidator;
use Tests\TestCase;

final class ApcomMissingOfferDecisionsV3Test extends TestCase
{
    public function test_v1_and_v2_are_preserved_while_v3_supersedes_v2_without_execution_approval(): void
    {
        $registry = app(SupplierHumanDecisionRegistry::class);
        $v1 = $registry->apcomV1()->toArray();
        $v2 = $registry->apcomV2()->toArray();
        $v3 = $registry->apcomV3();
        $profile = app(SupplierPreviewFeedProfileDesignRegistry::class)->apcomV3();

        $this->assertSame('apcom-human-decisions-v1', $v1['key']);
        $this->assertArrayNotHasKey('supersedes_key', $v1);
        $this->assertSame('apcom-human-decisions-v1', $v2['supersedes_key']);
        $this->assertSame('apcom-human-decisions-v2', $v3->toArray()['supersedes_key']);
        $this->assertTrue(app(SupplierHumanDecisionRegisterValidator::class)->validate($v3)['valid']);
        $this->assertTrue(app(SupplierPreviewFeedProfileDesignValidator::class)->validate($profile, $v3)['valid']);
        $this->assertSame('apcom-human-decisions-v3', $profile->decisionRegisterKey);
        $this->assertSame('apcom-approved-business-semantics-v2', $profile->semanticsProfileKey);
    }

    public function test_v3_confirms_missing_offer_semantics_but_keeps_source_mpn_zero_price_and_execution_safe(): void
    {
        $decisions = collect(app(SupplierHumanDecisionRegistry::class)->apcomV3()->toArray()['decisions'])->keyBy('decision_id');

        foreach (['APCOM-STAGING-ONLY-001', 'APCOM-LINKED-STAGING-ONLY-001', 'APCOM-MISSING-OFFER-REAPPEARANCE-001'] as $id) {
            $this->assertSame('confirmed', $decisions[$id]['status']);
            $this->assertFalse($decisions[$id]['automatic_execution_allowed']);
            $this->assertFalse($decisions[$id]['catalog_write_allowed']);
        }

        $this->assertSame('pending', $decisions['APCOM-SOURCE-ONLY-001']['status']);
        $this->assertSame('pending', $decisions['APCOM-MPN-001']['status']);
        $this->assertSame('review_only', $decisions['APCOM-ZERO-PRICE-001']['status']);
    }
}
