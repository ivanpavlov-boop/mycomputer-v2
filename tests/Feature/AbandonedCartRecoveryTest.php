<?php

namespace Tests\Feature;

use App\Filament\Resources\AbandonedCartRecords\AbandonedCartRecordResource;
use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EmailLog;
use App\Models\EmailSubscriber;
use App\Models\Product;
use App\Models\User;
use App\Services\Email\EmailMarketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AbandonedCartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        config()->set('email-marketing.provider', 'log');
        $this->seed();
        $this->product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->product->update(['price' => 100, 'promo_price' => null, 'quantity' => 10]);
    }

    public function test_guest_cart_becomes_abandoned_after_inactivity(): void
    {
        $cart = $this->staleCart('guest-cart', 'guest@example.com');

        $detected = app(EmailMarketingService::class)->detectAbandonedCarts();

        $this->assertSame(1, $detected);
        $this->assertDatabaseHas('abandoned_cart_records', [
            'session_id' => $cart->session_id,
            'email' => 'guest@example.com',
            'cart_total' => 100,
            'items_count' => 1,
            'status' => 'pending',
        ]);
        $this->assertNotNull(AbandonedCartRecord::query()->firstOrFail()->recovery_token);
    }

    public function test_authenticated_cart_becomes_abandoned_after_inactivity(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $cart = $this->staleCart('member-cart', null, $user);

        app(EmailMarketingService::class)->detectAbandonedCarts();

        $this->assertDatabaseHas('abandoned_cart_records', [
            'session_id' => $cart->session_id,
            'user_id' => $user->id,
            'email' => 'member@example.com',
        ]);
    }

    public function test_abandoned_cart_record_is_not_duplicated(): void
    {
        $this->staleCart('duplicate-cart', 'duplicate@example.com');

        app(EmailMarketingService::class)->detectAbandonedCarts();
        app(EmailMarketingService::class)->detectAbandonedCarts();

        $this->assertSame(1, AbandonedCartRecord::query()->where('session_id', 'duplicate-cart')->count());
    }

    public function test_email_sequence_sends_first_second_and_third_reminders(): void
    {
        $this->staleCart('sequence-cart', 'sequence@example.com', updatedAt: now()->subHours(2));
        app(EmailMarketingService::class)->detectAbandonedCarts();
        $record = AbandonedCartRecord::query()->firstOrFail();

        app(EmailMarketingService::class)->processDueAbandonedCarts();
        $record->refresh();

        $this->assertSame('emailed_once', $record->status);
        $this->assertNotNull($record->first_email_sent_at);
        $this->assertDatabaseHas('email_logs', ['email' => 'sequence@example.com', 'type' => 'abandoned_cart_1']);

        $this->travel(25)->hours();
        app(EmailMarketingService::class)->processDueAbandonedCarts();
        $record->refresh();

        $this->assertSame('emailed_twice', $record->status);
        $this->assertNotNull($record->second_email_sent_at);
        $this->assertDatabaseHas('email_logs', ['email' => 'sequence@example.com', 'type' => 'abandoned_cart_2']);

        $this->travel(73)->hours();
        app(EmailMarketingService::class)->processDueAbandonedCarts();
        $record->refresh();

        $this->assertSame('emailed_three_times', $record->status);
        $this->assertNotNull($record->third_email_sent_at);
        $this->assertDatabaseHas('email_logs', ['email' => 'sequence@example.com', 'type' => 'abandoned_cart_3']);
    }

    public function test_unsubscribed_email_does_not_receive_reminders(): void
    {
        EmailSubscriber::query()->create([
            'email' => 'quiet@example.com',
            'source' => 'newsletter',
            'status' => 'unsubscribed',
        ]);
        $this->staleCart('quiet-cart', 'quiet@example.com', updatedAt: now()->subHours(2));
        app(EmailMarketingService::class)->detectAbandonedCarts();

        app(EmailMarketingService::class)->processDueAbandonedCarts();

        $this->assertSame(0, EmailLog::query()->where('email', 'quiet@example.com')->count());
        $this->assertSame('suppressed', AbandonedCartRecord::query()->firstOrFail()->status);
    }

    public function test_recovery_token_restores_cart(): void
    {
        $this->staleCart('recover-cart', 'recover@example.com');
        app(EmailMarketingService::class)->detectAbandonedCarts();
        $record = AbandonedCartRecord::query()->firstOrFail();

        Cart::query()->where('session_id', 'recover-cart')->firstOrFail()->items()->delete();

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk()
            ->assertJsonPath('data.cart_session_id', 'recover-cart')
            ->assertJsonPath('data.items_count', 1);
    }

    public function test_expired_recovery_token_fails(): void
    {
        $this->staleCart('expired-cart', 'expired@example.com');
        app(EmailMarketingService::class)->detectAbandonedCarts();
        $record = AbandonedCartRecord::query()->firstOrFail();
        $record->update(['recovery_token_expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertUnprocessable()
            ->assertJsonPath('error.details.token.0', 'Recovery link has expired or is invalid.');
    }

    public function test_invalid_recovery_token_fails(): void
    {
        $this->postJson('/api/v1/cart/recover/not-a-real-token')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.token.0', 'Recovery link has expired or is invalid.');
    }

    public function test_no_email_is_sent_after_recovery(): void
    {
        $this->staleCart('recovered-email-cart', 'recovered-email@example.com', updatedAt: now()->subHours(2));
        app(EmailMarketingService::class)->detectAbandonedCarts();
        $record = AbandonedCartRecord::query()->firstOrFail();
        $record->update(['status' => 'recovered', 'recovered_at' => now()]);

        app(EmailMarketingService::class)->processDueAbandonedCarts();

        $this->assertSame(0, EmailLog::query()->where('email', 'recovered-email@example.com')->count());
    }

    public function test_checkout_after_recovery_marks_cart_recovered_and_tracks_revenue(): void
    {
        $this->staleCart('checkout-recover-cart', 'recover-checkout@example.com');
        app(EmailMarketingService::class)->detectAbandonedCarts();
        $record = AbandonedCartRecord::query()->firstOrFail();

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")->assertOk();

        $this->withHeader('X-Cart-Session', 'checkout-recover-cart')
            ->postJson('/api/v1/checkout', $this->checkoutPayload('recover-checkout@example.com'))
            ->assertCreated();

        $record->refresh();
        $this->assertSame('recovered', $record->status);
        $this->assertNotNull($record->recovered_at);
        $this->assertNotNull($record->recovered_order_id);
        $this->assertSame('108.99', $record->recovered_revenue);
        $this->assertDatabaseHas('marketing_events', ['event_name' => 'abandoned_cart_recovered']);
    }

    public function test_cart_email_endpoint_attaches_email_to_cart_and_record(): void
    {
        $this->staleCart('email-cart');
        app(EmailMarketingService::class)->detectAbandonedCarts();

        $this->withHeader('X-Cart-Session', 'email-cart')
            ->postJson('/api/v1/cart/email', ['email' => 'captured@example.com'])
            ->assertOk()
            ->assertJsonPath('data.cart_session_id', 'email-cart');

        $this->assertDatabaseHas('carts', ['session_id' => 'email-cart', 'customer_email' => 'captured@example.com']);
        $this->assertDatabaseHas('abandoned_cart_records', ['session_id' => 'email-cart', 'email' => 'captured@example.com']);
    }

    public function test_authenticated_user_cannot_access_another_users_cart(): void
    {
        $owner = User::factory()->create(['email' => 'cart-owner@example.test']);
        $other = User::factory()->create(['email' => 'cart-other@example.test']);
        $this->staleCart('owned-cart', 'owner@example.com', $owner);

        Sanctum::actingAs($other);

        $this
            ->withHeader('X-Cart-Session', 'owned-cart')
            ->getJson('/api/v1/cart')
            ->assertForbidden();
    }

    public function test_scheduled_commands_work(): void
    {
        $this->staleCart('command-cart', 'command@example.com', updatedAt: now()->subHours(2));

        Artisan::call('carts:detect-abandoned');
        Artisan::call('carts:process-abandoned');

        $this->assertDatabaseHas('abandoned_cart_records', ['session_id' => 'command-cart', 'status' => 'emailed_once']);
        $this->assertDatabaseHas('email_logs', ['email' => 'command@example.com', 'type' => 'abandoned_cart_1']);
    }

    public function test_admin_resource_access_requires_marketing_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $plain = User::factory()->create(['email' => 'plain-marketing-user@example.test']);
        $marketer = User::factory()->create(['email' => 'marketer@example.test']);
        $marketer->givePermissionTo('manage marketing');

        $this->actingAs($plain, 'web');
        $this->assertFalse(AbandonedCartRecordResource::canViewAny());

        Auth::guard('web')->logout();

        $this->actingAs($marketer, 'web');
        $this->assertTrue(AbandonedCartRecordResource::canViewAny());
    }

    private function staleCart(string $sessionId, ?string $email = null, ?User $user = null, mixed $updatedAt = null): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $sessionId,
            'user_id' => $user?->id,
            'customer_email' => $email,
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);

        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        DB::table('carts')
            ->where('id', $cart->id)
            ->update(['updated_at' => $updatedAt ?: now()->subMinutes(70)]);

        return $cart->fresh(['items.product', 'user']);
    }

    private function checkoutPayload(string $email): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => $email,
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'terms' => true,
        ];
    }
}
