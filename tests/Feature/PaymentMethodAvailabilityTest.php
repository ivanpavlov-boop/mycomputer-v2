<?php

namespace Tests\Feature;

use App\Exceptions\CardPaymentProviderUnavailableException;
use App\Exceptions\PaymentMethodUnavailableException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use App\Services\Payments\PaymentMethodAvailabilityService;
use App\Services\Payments\Providers\CardPaymentProvider;
use Database\Seeders\PaymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCardPaymentProvider;
use Tests\TestCase;

class PaymentMethodAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_methods_are_available_but_card_is_fail_closed_by_default(): void
    {
        config()->set('payments.methods.card.enabled', false);
        $this->seed();
        PaymentMethod::query()->where('code', 'card')->update(['status' => 'active']);

        $codes = app(PaymentMethodAvailabilityService::class)
            ->availableMethods()
            ->pluck('code')
            ->all();

        $this->assertSame(
            ['cash_on_delivery', 'bank_transfer', 'leasing'],
            $codes,
        );
        $this->getJson('/api/v1/payments/methods')
            ->assertOk()
            ->assertJsonMissing(['code' => 'card']);
    }

    public function test_card_requires_flag_active_records_and_operational_provider(): void
    {
        config()->set('payments.methods.card.enabled', true);
        $this->seed();
        $card = PaymentMethod::query()->where('code', 'card')->firstOrFail();
        $card->update(['status' => 'active']);

        $this->assertFalse(app(PaymentMethodAvailabilityService::class)->isAvailable($card->fresh('provider')));

        $fake = new FakeCardPaymentProvider;
        $this->app->instance(CardPaymentProvider::class, $fake);
        $this->assertTrue(app(PaymentMethodAvailabilityService::class)->isAvailable($card->fresh('provider')));

        $card->provider()->update(['status' => 'inactive']);
        $this->assertFalse(app(PaymentMethodAvailabilityService::class)->isAvailable($card->fresh('provider')));
    }

    public function test_unknown_and_missing_methods_fail_closed(): void
    {
        $this->seed();
        $provider = PaymentProvider::query()->where('code', 'manual')->firstOrFail();
        $unknown = PaymentMethod::query()->create([
            'payment_provider_id' => $provider->id,
            'name' => 'Unknown',
            'code' => 'unknown',
            'type' => 'online',
            'status' => 'active',
            'sort_order' => 99,
        ]);
        $availability = app(PaymentMethodAvailabilityService::class);

        $this->assertFalse($availability->isAvailable($unknown->fresh('provider')));

        $this->expectException(PaymentMethodUnavailableException::class);
        $availability->requireAvailable('missing');
    }

    public function test_method_without_an_assigned_provider_fails_closed(): void
    {
        $this->seed();
        $method = PaymentMethod::query()
            ->where('code', 'cash_on_delivery')
            ->firstOrFail();
        $method->update(['payment_provider_id' => null]);

        $this->assertFalse(
            app(PaymentMethodAvailabilityService::class)->isAvailable($method->fresh('provider')),
        );
    }

    public function test_seeded_card_is_inactive_and_existing_rows_are_not_mutated(): void
    {
        $this->seed(PaymentSeeder::class);
        $card = PaymentMethod::query()->where('code', 'card')->firstOrFail();

        $this->assertSame('inactive', $card->status);
        $this->assertSame(
            ['requires_provider_configuration' => true],
            $card->settings,
        );

        $card->update([
            'status' => 'active',
            'description' => 'Existing production configuration',
        ]);
        $this->seed(PaymentSeeder::class);

        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame('Existing production configuration', $card->fresh()->description);
    }

    public function test_production_card_provider_is_non_operational_and_fails_closed(): void
    {
        $provider = app(CardPaymentProvider::class);

        $this->assertFalse($provider->isOperational());
        $this->expectException(CardPaymentProviderUnavailableException::class);

        $provider->initiatePayment(new Order, []);
    }
}
