<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignValidator;
use App\Services\Suppliers\Onboarding\SupplierSourceFieldSemanticsRegistry;
use Tests\TestCase;

final class PreviewFeedProfileV2Test extends TestCase
{
    public function test_v2_preview_profile_references_v2_decisions_and_remains_non_executable(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v2');
        $profile = app(SupplierPreviewFeedProfileDesignRegistry::class)->find('apcom-preview-feed-profile-v2');
        $payload = $profile->toArray();

        $this->assertNotNull($register);
        $this->assertNotNull($profile);
        $this->assertTrue(app(SupplierPreviewFeedProfileDesignValidator::class)->validate($profile, $register)['valid']);
        $this->assertSame('apcom-human-decisions-v2', $payload['decision_register_key']);
        $this->assertSame('apcom-approved-business-semantics-v2', $payload['semantics_profile_key']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['persisted']);
        $this->assertFalse($payload['executable']);
        $this->assertFalse($payload['safety_policy']['import_allowed']);
        $this->assertFalse($payload['safety_policy']['catalog_sync_allowed']);
        $this->assertFalse($payload['safety_policy']['schedule_enablement_allowed']);
        $this->assertFalse($payload['safety_policy']['profile_persistence_allowed']);
    }

    public function test_v2_semantics_keep_exact_quantity_internal_and_price_tax_rules_preview_only(): void
    {
        $semantics = app(SupplierSourceFieldSemanticsRegistry::class)->find('apcom-approved-business-semantics-v2');

        $this->assertNotNull($semantics);
        $this->assertTrue($semantics->usesObservedNumericStockContract());
        $this->assertTrue($semantics->hasApprovedSupplierAvailabilitySemantics());
        $this->assertSame('fd_price', $semantics->fieldMap['selected_price']);
        $this->assertFalse($semantics->stockSemantics['public_exact_quantity_allowed']);
        $this->assertFalse($semantics->stockSemantics['automatic_quantity_mapping_allowed']);
        $this->assertFalse($semantics->stockSemantics['automatic_availability_mapping_allowed']);
    }

    public function test_v1_preview_profile_remains_registered_with_its_original_dependencies(): void
    {
        $profile = app(SupplierPreviewFeedProfileDesignRegistry::class)->find('apcom-preview-feed-profile-v1');

        $this->assertSame('apcom-human-decisions-v1', $profile->decisionRegisterKey);
        $this->assertSame('apcom-observed-stock-v1', $profile->semanticsProfileKey);
        $this->assertSame([], $profile->safetyPolicy);
    }
}
