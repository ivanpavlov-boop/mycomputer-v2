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
use Illuminate\Support\Facades\Route;
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

        $missingLegal = $this->preflight();
        $this->assertSame(1, $missingLegal['exit_code']);
        $this->assertContains('terms_route_present', $missingLegal['payload']['blockers']);
        $this->assertContains('privacy_route_present', $missingLegal['payload']['blockers']);

        Route::get('/terms', fn () => 'terms');
        Route::get('/privacy', fn () => 'privacy');

        $before = $this->databaseCounts();
        $closed = $this->preflight();
        $this->assertSame(0, $closed['exit_code']);
        $this->assertSame('closed', $closed['payload']['state']);
        $this->assertTrue($closed['payload']['ready_for_activation']);
        $this->assertSame('/terms', $closed['payload']['legal_routes']['terms']);
        $this->assertSame('/privacy', $closed['payload']['legal_routes']['privacy']);
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

        $result = $this->preflight();

        $this->assertSame(1, $result['exit_code']);
        $this->assertFalse($result['payload']['ready_for_activation']);
        $this->assertContains('database_accessible', $result['payload']['blockers']);
        $this->assertStringNotContainsString('Sensitive connection details', Artisan::output());
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
