<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LegalAcceptanceFilamentTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_legal_acceptance_is_visible_but_cannot_be_edited(): void
    {
        $cart = $this->prepareCheckoutCart('legal-filament');
        $this->submitCheckout($cart, 'legal-filament')->assertCreated();
        $order = Order::query()->sole();
        $acceptedAt = $order->legal_accepted_at?->toISOString();
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($superAdmin);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->assertSee('Правно приемане')
            ->assertSee('Версия на Общите условия')
            ->assertSee('Версия на Политиката за поверителност')
            ->assertSchemaComponentExists('terms_version')
            ->assertSchemaComponentExists('privacy_version')
            ->assertSchemaComponentExists('legal_accepted_at')
            ->assertSchemaComponentExists('legal_acceptance_locale')
            ->assertFormSet([
                'terms_version' => 'terms-test-1',
                'privacy_version' => 'privacy-test-1',
                'legal_acceptance_locale' => 'bg',
            ])
            ->fillForm([
                'status' => 'confirmed',
                'legal_accepted_at' => '1999-01-01 00:00:00',
                'terms_version' => 'changed-terms',
                'privacy_version' => 'changed-privacy',
                'legal_acceptance_locale' => 'en',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($acceptedAt, $order->legal_accepted_at?->toISOString());
        $this->assertSame('terms-test-1', $order->terms_version);
        $this->assertSame('privacy-test-1', $order->privacy_version);
        $this->assertSame('bg', $order->legal_acceptance_locale);
    }

    public function test_historical_null_legal_acceptance_renders_safely(): void
    {
        $cart = $this->prepareCheckoutCart('legal-filament-historical');
        $this->submitCheckout($cart, 'legal-filament-historical')->assertCreated();
        $order = Order::query()->sole();
        $order->update([
            'legal_accepted_at' => null,
            'terms_version' => null,
            'privacy_version' => null,
            'legal_acceptance_locale' => null,
        ]);
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($superAdmin);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->assertSee('Правно приемане')
            ->assertSee('Няма данни');
    }
}
