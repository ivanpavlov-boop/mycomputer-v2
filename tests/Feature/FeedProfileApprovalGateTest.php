<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierFeedProfileApprovalGateFactory;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignRegistry;
use Tests\TestCase;

final class FeedProfileApprovalGateTest extends TestCase
{
    public function test_v2_approval_gate_is_valid_but_blocked_and_never_grants_execution(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v2');
        $profile = app(SupplierPreviewFeedProfileDesignRegistry::class)->find('apcom-preview-feed-profile-v2');
        $gate = app(SupplierFeedProfileApprovalGateFactory::class)->create($profile, $register)->toArray();

        $this->assertTrue($gate['valid']);
        $this->assertSame('blocked_pending_human_decisions', $gate['gate_status']);
        $this->assertFalse($gate['semantic_confirmation_complete']);
        $this->assertFalse($gate['operational_import_approval']);
        $this->assertFalse($gate['profile_persistence_approval']);
        $this->assertFalse($gate['schedule_enablement_approval']);
        $this->assertFalse($gate['catalog_sync_approval']);
        $this->assertTrue($gate['human_review_required']);
        $this->assertContains('APCOM-MPN-001', $gate['pending_decision_ids']);
        $this->assertContains('APCOM-SOURCE-ONLY-001', $gate['pending_decision_ids']);
        $this->assertContains('APCOM-ZERO-PRICE-001', $gate['review_only_decision_ids']);
        $this->assertContains('APCOM-PROHIBIT-AUTO-IMPORT-001', $gate['prohibited_action_ids']);
        $this->assertContains('snapshot_freshness_threshold_pending', $gate['unresolved_policy_reasons']);
    }
}
