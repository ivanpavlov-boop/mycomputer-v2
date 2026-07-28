<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMethodAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeasingPaymentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_database_record_cannot_bypass_disabled_feature_flag(): void
    {
        config()->set('payments.methods.leasing.enabled', false);
        $this->seed();
        $leasing = PaymentMethod::query()->where('code', 'leasing')->firstOrFail();
        $leasing->update(['status' => 'active']);

        $this->assertFalse(app(PaymentMethodAvailabilityService::class)->isAvailable($leasing->fresh('provider')));
        $this->getJson('/api/v1/payments/methods')
            ->assertOk()
            ->assertJsonMissing(['code' => 'leasing']);
    }

    public function test_enabled_leasing_exposes_only_safe_configured_options(): void
    {
        $this->enableLeasing();

        $response = $this->getJson('/api/v1/payments/methods')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'leasing',
                'name' => 'Покупка на изплащане',
            ])
            ->assertJsonPath('data.2.options.term_months', [6, 12, 18, 24, 36, 48])
            ->assertJsonPath('data.2.options.currency', 'EUR')
            ->assertJsonMissingPath('data.2.options.notification_email')
            ->assertJsonMissingPath('data.2.options.enabled')
            ->assertJsonMissingPath('data.2.options.consent_version');

        $json = $response->getContent();
        $this->assertStringNotContainsString('sales@mycomputer.bg', $json);
        $this->assertStringNotContainsString('provider', strtolower($json));
    }

    private function enableLeasing(): void
    {
        config()->set('payments.methods.leasing.enabled', true);
        $this->seed();
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);
    }
}
