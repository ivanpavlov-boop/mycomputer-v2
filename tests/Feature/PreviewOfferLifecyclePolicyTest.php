<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PreviewOfferLifecyclePolicyTest extends TestCase
{
    public function test_command_uses_deterministic_synthetic_preview_data_and_never_writes(): void
    {
        Bus::fake();
        Http::fake();

        $this->assertSame(0, Artisan::call('suppliers:preview-offer-lifecycle-policy', [
            '--supplier' => 'apcom', '--scenario' => 'all', '--format' => 'json',
        ]));
        $first = Artisan::output();
        $payload = json_decode($first, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('preview_only', $payload['mode']);
        $this->assertTrue($payload['success']);
        $this->assertSame('offer_lifecycle_policy_confirmed_execution_blocked', $payload['verdict']);
        $this->assertSame('supplier-offer-missing-policy-v1', $payload['missing_offer_policy']['policy_key']);
        $this->assertSame('supplier-offer-reappearance-policy-v1', $payload['reappearance_policy']['policy_key']);
        $this->assertSame('catalog-offer-aggregation-policy-v1', $payload['catalog_offer_aggregation_policy']['policy_key']);
        $this->assertSame('catalog-product-visibility-lifecycle-policy-v1', $payload['catalog_visibility_lifecycle_policy']['policy_key']);
        $this->assertSame('catalog-product-deletion-policy-v1', $payload['deletion_policy']['policy_key']);
        $this->assertSame('supplier-technical-retention-policy-v1', $payload['technical_retention_policy']['policy_key']);
        $this->assertSame('apcom-human-decisions-v3', $payload['policy_versions']['apcom_human_decisions']);
        $this->assertSame('apcom-preview-feed-profile-v3', $payload['policy_versions']['apcom_preview_feed_profile']);
        $this->assertFalse($payload['approval_gate']['offer_lifecycle_write_approval']);
        $this->assertFalse($payload['approval_gate']['product_visibility_write_approval']);
        $this->assertFalse($payload['approval_gate']['catalog_sync_approval']);

        foreach ($payload['records_changed'] as $count) {
            $this->assertSame(0, $count);
        }

        $this->assertCount(9, $payload['synthetic_scenarios']['snapshot_qualification']);
        $this->assertCount(14, $payload['synthetic_scenarios']['offer_lifecycle']);
        $this->assertCount(7, $payload['synthetic_scenarios']['multi_supplier_aggregation']);
        $this->assertCount(8, $payload['synthetic_scenarios']['visibility_lifecycle']);
        $this->assertCount(4, $payload['synthetic_scenarios']['deletion']);
        $this->assertFalse($payload['safety']['database_accessed']);
        $this->assertFalse($payload['safety']['http_requested']);
        $this->assertFalse($payload['safety']['catalog_sync_called']);
        $this->assertTrue($payload['safety']['synthetic_data_only']);
        $this->assertStringNotContainsString('VMA3600', $first);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();

        $this->assertSame(0, Artisan::call('suppliers:preview-offer-lifecycle-policy', [
            '--supplier' => 'apcom', '--scenario' => 'all', '--format' => 'json',
        ]));
        $this->assertSame($first, Artisan::output());
    }

    public function test_command_exposes_only_bounded_safe_options(): void
    {
        $command = Artisan::all()['suppliers:preview-offer-lifecycle-policy'];

        foreach (['supplier', 'scenario', 'format'] as $option) {
            $this->assertTrue($command->getDefinition()->hasOption($option));
        }
        foreach (['apply', 'persist', 'approve', 'activate', 'deactivate', 'reactivate', 'import', 'create', 'update', 'delete', 'link', 'unlink', 'sync', 'sync-all', 'schedule', 'enable', 'images', 'dispatch', 'queue', 'remote', 'fetch', 'download', 'source', 'report'] as $option) {
            $this->assertFalse($command->getDefinition()->hasOption($option));
        }
    }
}
