<?php

namespace Tests\Feature;

use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Payments\PaymentMethodAvailabilityService;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\ShippingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CommerceReleasePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_is_read_only_and_reports_each_release_blocker(): void
    {
        $this->seed([PaymentSeeder::class, ShippingSeeder::class]);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $this->safeConfiguration(false, false);

        config()->set('legal.approved', false);
        config()->set(
            'legal.manifest_path',
            base_path('frontend/app/data/legal/legal-content-manifest.json'),
        );
        config()->set(
            'legal.approval_record_path',
            base_path('docs/legal/LEGAL_CONTENT_APPROVAL_2026-07-30.json'),
        );
        config()->set('legal.source_pages', [
            'terms' => base_path('frontend/app/data/legal/terms.bg.ts'),
            'privacy' => base_path('frontend/app/data/legal/privacy.bg.ts'),
        ]);
        $committed = $this->preflight();
        $this->assertSame(1, $committed['exit_code']);
        $this->assertSame(['legal_content_approved'], $committed['payload']['blockers']);
        $this->assertTrue($committed['payload']['checks']['legal_manifest_valid']);
        $this->assertTrue($committed['payload']['checks']['legal_effective_dates_present']);
        $this->assertFalse($committed['payload']['checks']['legal_content_approved']);

        $this->useApprovedLegalFixture();
        $before = $this->databaseCounts();
        $closed = $this->preflight();
        $this->assertSame(0, $closed['exit_code']);
        $this->assertSame('closed', $closed['payload']['state']);
        $this->assertTrue($closed['payload']['ready_for_activation']);
        $this->assertSame('/obshti-usloviya', $closed['payload']['legal_routes']['terms']);
        $this->assertSame('/politika-za-poveritelnost', $closed['payload']['legal_routes']['privacy']);
        $this->assertTrue($closed['payload']['checks']['legal_manifest_valid']);
        $this->assertTrue($closed['payload']['checks']['legal_acceptance_schema_present']);
        $this->assertSame($before, $this->databaseCounts());

        $this->safeConfiguration(true, true);
        $open = $this->preflight();
        $this->assertSame(0, $open['exit_code']);
        $this->assertSame('open', $open['payload']['state']);

        config()->set('commerce.public.confirmation_enabled', false);
        $invalid = $this->preflight();
        $this->assertSame(1, $invalid['exit_code']);
        $this->assertSame('invalid', $invalid['payload']['state']);
        $this->assertContains('configuration_valid', $invalid['payload']['blockers']);

        $this->safeConfiguration(false, false);
        config()->set('payments.methods.card.enabled', true);
        $this->assertContains('card_disabled', $this->preflight()['payload']['blockers']);

        config()->set('payments.methods.card.enabled', false);
        config()->set('payments.methods.leasing.enabled', true);
        $this->assertContains('leasing_disabled', $this->preflight()['payload']['blockers']);

        config()->set('payments.methods.leasing.enabled', false);
        config()->set('catalog_sync.update_enabled', true);
        $this->assertContains('catalog_sync_safe', $this->preflight()['payload']['blockers']);

        config()->set('catalog_sync.update_enabled', false);
        config()->set('catalog_sync.sync_all_enabled', true);
        $this->assertContains('catalog_sync_safe', $this->preflight()['payload']['blockers']);

        config()->set('catalog_sync.sync_all_enabled', false);
        config()->set('catalog_sync.auto_enabled', true);
        $this->assertContains('catalog_sync_safe', $this->preflight()['payload']['blockers']);

        config()->set('catalog_sync.auto_enabled', false);
        config()->set('commerce.abandoned_cart_recovery.enabled', true);
        $this->assertContains(
            'abandoned_cart_recovery_disabled',
            $this->preflight()['payload']['blockers'],
        );

        config()->set('commerce.abandoned_cart_recovery.enabled', false);
        $superAdmin->update(['is_active' => false]);
        $this->assertContains(
            'active_super_admin_present',
            $this->preflight()['payload']['blockers'],
        );

        $superAdmin->update(['is_active' => true]);
        ShippingMethod::query()->update(['status' => 'inactive']);
        $this->assertContains('shipping_available', $this->preflight()['payload']['blockers']);
    }

    public function test_preflight_fails_closed_without_exposing_an_exception_when_a_readiness_source_is_unavailable(): void
    {
        $this->mock(PaymentMethodAvailabilityService::class)
            ->shouldReceive('availableMethods')
            ->once()
            ->andThrow(new RuntimeException('Sensitive connection details'));
        Schema::shouldReceive('hasColumn')
            ->once()
            ->andThrow(new RuntimeException('Sensitive schema connection details'));

        $result = $this->preflight();

        $this->assertSame(1, $result['exit_code']);
        $this->assertFalse($result['payload']['ready_for_activation']);
        $this->assertContains('database_accessible', $result['payload']['blockers']);
        $this->assertContains('legal_acceptance_schema_present', $result['payload']['blockers']);
        $this->assertStringNotContainsString('Sensitive connection details', Artisan::output());
        $this->assertStringNotContainsString(
            'Sensitive schema connection details',
            Artisan::output(),
        );
    }

    private function safeConfiguration(bool $enabled, bool $confirmationEnabled): void
    {
        config()->set('commerce.public.enabled', $enabled);
        config()->set('commerce.public.confirmation_enabled', $confirmationEnabled);
        config()->set('commerce.abandoned_cart_recovery.enabled', false);
        config()->set('payments.methods.card.enabled', false);
        config()->set('payments.methods.leasing.enabled', false);
        config()->set('catalog_sync.update_enabled', false);
        config()->set('catalog_sync.sync_all_enabled', false);
        config()->set('catalog_sync.auto_enabled', false);
    }

    private function useApprovedLegalFixture(): void
    {
        config()->set('legal.approved', true);
        config()->set(
            'legal.manifest_path',
            base_path('tests/Fixtures/Legal/approved-legal-content-manifest.json'),
        );
        config()->set(
            'legal.approval_record_path',
            base_path('tests/Fixtures/Legal/approved-legal-content-approval.json'),
        );
        config()->set('legal.source_pages', [
            'terms' => base_path('tests/Fixtures/Legal/approved-terms.bg.ts'),
            'privacy' => base_path('tests/Fixtures/Legal/approved-privacy.bg.ts'),
        ]);
    }

    /**
     * @return array{exit_code: int, payload: array<string, mixed>}
     */
    private function preflight(): array
    {
        $exitCode = Artisan::call('commerce:release-preflight', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return [
            'exit_code' => $exitCode,
            'payload' => $payload,
        ];
    }

    private function databaseCounts(): array
    {
        return collect([
            'users',
            'payment_methods',
            'payment_providers',
            'shipping_methods',
            'shipping_providers',
            'orders',
            'carts',
            'products',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();
    }
}
