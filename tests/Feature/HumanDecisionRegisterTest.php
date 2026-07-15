<?php

namespace Tests\Feature;

use App\Data\Suppliers\Onboarding\SupplierHumanDecision;
use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegisterValidator;
use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use Tests\TestCase;

final class HumanDecisionRegisterTest extends TestCase
{
    public function test_apcom_human_decision_register_is_versioned_stable_and_non_executable(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v1');

        $this->assertNotNull($register);
        $validation = app(SupplierHumanDecisionRegisterValidator::class)->validate($register);
        $payload = $register->toArray();

        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['errors']);
        $this->assertSame('apcom-human-decisions-v1', $payload['key']);
        $this->assertSame('apcom', $payload['supplier_key']);
        $this->assertFalse($payload['persisted']);
        $this->assertTrue($payload['read_only']);

        $ids = array_column($payload['decisions'], 'decision_id');
        $sortedIds = $ids;
        sort($sortedIds, SORT_STRING);
        $this->assertSame($sortedIds, $ids);
        $this->assertContains('APCOM-ID-001', $ids);
        $this->assertContains('APCOM-SOURCE-001', $ids);
        $this->assertContains('APCOM-STOCK-001', $ids);
        $this->assertContains('APCOM-PROHIBIT-AUTO-SYNC-001', $ids);

        foreach ($payload['decisions'] as $decision) {
            $this->assertFalse($decision['automatic_execution_allowed']);
            $this->assertFalse($decision['catalog_write_allowed']);
            $this->assertFalse($decision['staging_write_allowed']);
            $this->assertFalse($decision['profile_persistence_allowed']);
        }
    }

    public function test_validator_rejects_unknown_duplicate_and_unsafe_decisions(): void
    {
        $unsafe = new SupplierHumanDecision(
            decisionId: 'APCOM-UNSAFE-001',
            subject: 'Unsafe test decision',
            status: 'not_a_status',
            sourceFieldOrAction: 'test action',
            proposedRole: 'test',
            approvedRole: null,
            evidenceRequirement: null,
            evidenceReference: null,
            rationale: 'Test-only invalid decision.',
            humanReviewRequired: true,
            automaticExecutionAllowed: true,
            catalogWriteAllowed: true,
            stagingWriteAllowed: true,
            profilePersistenceAllowed: true,
            blockingDecision: true,
            notes: null,
        );
        $register = new SupplierHumanDecisionRegister('test-human-decisions-v1', 'apcom', [$unsafe, $unsafe]);

        $validation = app(SupplierHumanDecisionRegisterValidator::class)->validate($register);

        $this->assertFalse($validation['valid']);
        $this->assertContains('duplicate_decision_id:APCOM-UNSAFE-001', $validation['errors']);
        $this->assertContains('unknown_decision_status:APCOM-UNSAFE-001', $validation['errors']);
        $this->assertContains('required_decision_missing:APCOM-ID-001', $validation['errors']);
    }
}
