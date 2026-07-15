<?php

namespace Tests\Feature;

use App\Services\Suppliers\Onboarding\SupplierHumanDecisionRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignRegistry;
use App\Services\Suppliers\Onboarding\SupplierPreviewFeedProfileDesignValidator;
use Tests\TestCase;

final class PreviewFeedProfileDesignTest extends TestCase
{
    public function test_apcom_preview_feed_profile_is_read_only_non_persisted_and_non_executable(): void
    {
        $register = app(SupplierHumanDecisionRegistry::class)->find('apcom-human-decisions-v1');
        $profile = app(SupplierPreviewFeedProfileDesignRegistry::class)->find('apcom-preview-feed-profile-v1');

        $this->assertNotNull($register);
        $this->assertNotNull($profile);
        $validation = app(SupplierPreviewFeedProfileDesignValidator::class)->validate($profile, $register);
        $payload = $profile->toArray();

        $this->assertTrue($validation['valid']);
        $this->assertSame('apcom-preview-feed-profile-v1', $payload['key']);
        $this->assertSame('apcom-human-decisions-v1', $payload['decision_register_key']);
        $this->assertSame('apcom-observed-stock-v1', $payload['semantics_profile_key']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['persisted']);
        $this->assertFalse($payload['executable']);

        $mappings = collect($payload['field_mappings'])->keyBy('field');
        $this->assertSame('xml.product.partno', $mappings['supplier_sku']['source_path']);
        $this->assertSame('diagnostic_only', $mappings['ean_gtin']['proposed_role']);
        $this->assertSame('unresolved', $mappings['quantity']['proposed_role']);
        $this->assertSame('review_only', $mappings['eol']['proposed_role']);
        $this->assertSame('presence_only_no_import', $mappings['image_presence']['proposed_role']);

        foreach (array_merge($payload['field_mappings'], $payload['action_matrix']) as $entry) {
            $this->assertFalse($entry['automatic_execution_allowed']);
            $this->assertFalse($entry['catalog_write_allowed']);
            $this->assertFalse($entry['staging_write_allowed']);
        }
    }
}
