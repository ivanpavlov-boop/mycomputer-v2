<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierOfferLifecycleApprovalGateFactory;
use Tests\TestCase;

final class OfferLifecycleApprovalGateTest extends TestCase
{
    public function test_offer_lifecycle_gate_confirms_policy_but_blocks_all_implementation_approvals(): void
    {
        $gate = app(SupplierOfferLifecycleApprovalGateFactory::class)->create()->toArray();

        $this->assertTrue($gate['structural_validation']);
        $this->assertTrue($gate['missing_offer_policy_confirmed']);
        $this->assertTrue($gate['catalog_visibility_policy_confirmed']);
        $this->assertTrue($gate['technical_retention_policy_confirmed']);
        $this->assertFalse($gate['semantic_confirmation_complete']);
        foreach (['operational_import_approval', 'profile_persistence_approval', 'offer_lifecycle_write_approval', 'product_visibility_write_approval', 'schedule_enablement_approval', 'catalog_sync_approval'] as $key) {
            $this->assertFalse($gate[$key]);
        }
        $this->assertSame('blocked_pending_implementation_approvals', $gate['gate_status']);
        $this->assertTrue($gate['human_review_required']);
    }
}
