<?php

namespace Tests\Feature;

use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\EmailLog;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Email\EmailMarketingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AbandonedCartRecoverySafetyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        config()->set('email-marketing.provider', 'log');
        $this->product = Product::factory()->create([
            'price' => 125,
            'regular_price' => 125,
            'promo_price' => null,
            'quantity' => 20,
        ]);
    }

    public function test_valid_token_restores_once_and_replay_is_non_destructive(): void
    {
        $record = $this->record([$this->snapshotLine()]);

        $response = $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $sessionId = $response->json('data.cart_session_id');
        $restored = Cart::query()->where('session_id', $sessionId)->firstOrFail();
        $record->refresh();

        $this->assertTrue(Str::isUuid($sessionId));
        $this->assertSame('restored', $record->status);
        $this->assertNotNull($record->restored_at);
        $this->assertSame($restored->id, $record->restored_cart_id);
        $this->assertNull($record->recovered_at);
        $this->assertNull($record->recovered_order_id);
        $this->assertSame(1, DB::table('marketing_events')->where('event_name', 'abandoned_cart_restored')->count());
        $eventPayload = (string) DB::table('marketing_events')
            ->where('event_name', 'abandoned_cart_restored')
            ->value('payload');
        $this->assertStringNotContainsString($record->recovery_token, $eventPayload);
        $this->assertStringNotContainsString((string) $record->email, $eventPayload);
        $this->assertStringNotContainsString($record->session_id, $eventPayload);
        $this->assertNull(app(EmailMarketingService::class)->recordAbandonedCart($restored->fresh('items.product')));

        $before = $restored->items()->get()->map->only([
            'product_id',
            'quantity',
            'is_gift',
            'unit_price',
            'total_price',
        ])->all();

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_recovery_consumed')
            ->assertJsonPath('error.message', 'This recovery link has already been used.');

        $this->assertSame($before, $restored->fresh()->items()->get()->map->only([
            'product_id',
            'quantity',
            'is_gift',
            'unit_price',
            'total_price',
        ])->all());
        $this->assertSame(1, DB::table('marketing_events')->where('event_name', 'abandoned_cart_restored')->count());
    }

    public function test_unknown_expired_and_suppressed_tokens_share_generic_unavailable_contract(): void
    {
        $expired = $this->record([$this->snapshotLine()], expiresAt: now()->subMinute());
        $suppressed = $this->record([$this->snapshotLine()], status: 'suppressed');

        foreach ([
            'unknown-token',
            $expired->recovery_token,
            $suppressed->recovery_token,
        ] as $token) {
            $this->postJson("/api/v1/cart/recover/{$token}")
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'cart_recovery_invalid')
                ->assertJsonPath('error.message', 'Recovery link has expired or is invalid.');
        }

        $this->assertNull($expired->fresh()->restored_at);
        $this->assertNull($suppressed->fresh()->restored_at);
    }

    public function test_record_owner_does_not_authenticate_guest_but_same_user_may_own_restore(): void
    {
        $owner = User::factory()->create();
        $guestRecord = $this->record([$this->snapshotLine()], user: $owner);

        $guestResponse = $this->postJson("/api/v1/cart/recover/{$guestRecord->recovery_token}")
            ->assertOk();
        $guestCart = Cart::query()
            ->where('session_id', $guestResponse->json('data.cart_session_id'))
            ->firstOrFail();
        $this->assertNull($guestCart->user_id);

        $ownedRecord = $this->record([$this->snapshotLine()], user: $owner);
        Sanctum::actingAs($owner);
        $ownedResponse = $this->postJson("/api/v1/cart/recover/{$ownedRecord->recovery_token}")
            ->assertOk();

        $this->assertSame(
            $owner->id,
            Cart::query()
                ->where('session_id', $ownedResponse->json('data.cart_session_id'))
                ->value('user_id'),
        );
    }

    public function test_cross_user_restore_and_foreign_target_are_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $record = $this->record([$this->snapshotLine()], user: $owner);
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cart_recovery_forbidden');
        $this->assertNull($record->fresh()->restored_at);

        Auth::forgetGuards();
        Sanctum::actingAs($owner);
        $foreignCart = $this->cart('foreign-recovery-target', $other);
        $secondRecord = $this->record([$this->snapshotLine()], user: $owner);

        $this->withHeader('X-Cart-Session', $foreignCart->session_id)
            ->postJson("/api/v1/cart/recover/{$secondRecord->recovery_token}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cart_recovery_forbidden');
        $this->assertSame(0, $foreignCart->items()->count());
        $this->assertNull($secondRecord->fresh()->restored_at);
    }

    public function test_guest_record_may_be_restored_by_current_authenticated_user(): void
    {
        $actor = User::factory()->create();
        $record = $this->record([$this->snapshotLine()]);
        Sanctum::actingAs($actor);

        $response = $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk();

        $this->assertSame(
            $actor->id,
            Cart::query()
                ->where('session_id', $response->json('data.cart_session_id'))
                ->value('user_id'),
        );
    }

    public function test_empty_or_unknown_session_is_used_but_populated_targets_are_never_cleared(): void
    {
        $empty = $this->cart('empty-recovery-target');
        $emptyRecord = $this->record([$this->snapshotLine()]);
        $this->withHeader('X-Cart-Session', $empty->session_id)
            ->postJson("/api/v1/cart/recover/{$emptyRecord->recovery_token}")
            ->assertOk()
            ->assertJsonPath('data.cart_session_id', $empty->session_id);

        $unknownSession = $this->cartSession('unknown-recovery-target');
        $unknownRecord = $this->record([$this->snapshotLine()]);
        $this->withHeader('X-Cart-Session', $unknownSession)
            ->postJson("/api/v1/cart/recover/{$unknownRecord->recovery_token}")
            ->assertOk()
            ->assertJsonPath('data.cart_session_id', $unknownSession);

        foreach (['paid', 'gift', 'bundle', 'coupon'] as $kind) {
            $target = $this->populatedCart($kind);
            $before = $this->cartState($target);
            $record = $this->record([$this->snapshotLine()]);

            $response = $this->withHeader('X-Cart-Session', $target->session_id)
                ->postJson("/api/v1/cart/recover/{$record->recovery_token}")
                ->assertOk();

            $this->assertNotSame($target->session_id, $response->json('data.cart_session_id'));
            $this->assertSame($before, $this->cartState($target->fresh()));
            $this->assertNotSame($target->id, $record->fresh()->restored_cart_id);
        }
    }

    public function test_historical_target_is_unchanged_and_malformed_session_uses_shared_validation(): void
    {
        $historical = $this->cart('historical-recovery-target');
        $historical->update(['status' => 'expired', 'expires_at' => now()->subMinute()]);
        $record = $this->record([$this->snapshotLine()]);

        $response = $this->withHeader('X-Cart-Session', $historical->session_id)
            ->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk();

        $this->assertNotSame($historical->session_id, $response->json('data.cart_session_id'));
        $this->assertSame('expired', $historical->fresh()->status);

        $invalidRecord = $this->record([$this->snapshotLine()]);
        $this->withHeader('X-Cart-Session', 'not-a-canonical-uuid')
            ->postJson("/api/v1/cart/recover/{$invalidRecord->recovery_token}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_cart_session');
        $this->assertNull($invalidRecord->fresh()->restored_at);
    }

    public function test_restore_plan_preserves_paid_gift_identity_and_current_prices(): void
    {
        $this->product->update(['price' => 150, 'regular_price' => 150]);
        $promotion = Promotion::query()->create([
            'name' => 'Recovery gift',
            'type' => 'gift_product',
            'status' => 'active',
            'stackable' => true,
        ]);
        $promotion->actions()->create([
            'action_type' => 'gift_product',
            'configuration' => ['product_id' => $this->product->id, 'quantity' => 1],
        ]);
        $record = $this->record([
            $this->snapshotLine(quantity: 2),
            $this->snapshotLine(isGift: true, promotionId: $promotion->id),
        ]);

        $response = $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk();
        $cart = Cart::query()
            ->where('session_id', $response->json('data.cart_session_id'))
            ->firstOrFail();
        $lines = $cart->items()->orderBy('is_gift')->get();

        $this->assertCount(2, $lines);
        $this->assertFalse($lines[0]->is_gift);
        $this->assertSame(2, $lines[0]->quantity);
        $this->assertSame(150.0, (float) $lines[0]->unit_price);
        $this->assertSame(300.0, (float) $lines[0]->total_price);
        $this->assertTrue($lines[1]->is_gift);
        $this->assertSame(0.0, (float) $lines[1]->unit_price);
        $this->assertSame(0.0, (float) $lines[1]->total_price);
    }

    public function test_duplicate_snapshot_fails_without_target_or_token_consumption(): void
    {
        $record = $this->record([
            $this->snapshotLine(),
            $this->snapshotLine(quantity: 2),
        ]);
        $cartCount = Cart::query()->count();

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cart_recovery_requires_review')
            ->assertJsonPath('error.message', 'The saved cart cannot be restored automatically.');

        $this->assertSame($cartCount, Cart::query()->count());
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertNull($record->fresh()->restored_at);
        $this->assertNull($record->fresh()->restored_cart_id);
    }

    public function test_unavailable_products_follow_skip_policy_and_restored_records_receive_no_email(): void
    {
        $unavailable = Product::factory()->manualDraft()->create();
        $record = $this->record([[
            'product_id' => $unavailable->id,
            'quantity' => 1,
            'is_gift' => false,
        ]], email: 'restored-no-email@example.test');

        $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);

        app(EmailMarketingService::class)->processDueAbandonedCarts();

        $this->assertSame('restored', $record->fresh()->status);
        $this->assertSame(
            0,
            EmailLog::query()->where('email', 'restored-no-email@example.test')->count(),
        );
    }

    public function test_recovery_state_migration_rollback_fails_closed_when_restore_audit_exists(): void
    {
        $cart = $this->cart('restore-rollback-guard');
        $record = $this->record([$this->snapshotLine()]);
        $record->update([
            'status' => 'restored',
            'restored_at' => now(),
            'restored_cart_id' => $cart->id,
        ]);
        $migration = require base_path(
            'database/migrations/2026_07_25_090001_add_abandoned_cart_restore_state.php',
        );

        $this->expectException(RuntimeException::class);

        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'restored_at'));
            $this->assertTrue(Schema::hasColumn('abandoned_cart_records', 'restored_cart_id'));
            $this->assertSame('restored', $record->fresh()->status);
        }
    }

    public function test_restored_cart_merge_remaps_record_and_later_checkout_marks_only_it_recovered(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $canonical = $this->cart('recovery-canonical', $user);
        $record = $this->record([$this->snapshotLine()], user: $user);
        $unrelated = $this->record([$this->snapshotLine()]);
        Sanctum::actingAs($user);

        $restoredSession = $this->postJson("/api/v1/cart/recover/{$record->recovery_token}")
            ->assertOk()
            ->json('data.cart_session_id');
        $restored = Cart::query()->where('session_id', $restoredSession)->firstOrFail();

        $this->withHeader('X-Cart-Session', $canonical->session_id)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.cart_session_id', $canonical->session_id);

        $this->assertSame('merged', $restored->fresh()->status);
        $this->assertSame('restored', $record->fresh()->status);
        $this->assertSame($canonical->id, $record->fresh()->restored_cart_id);

        $this->withHeader('X-Cart-Session', $canonical->session_id)
            ->postJson('/api/v1/checkout', $this->checkoutPayload($user->email))
            ->assertCreated();

        $record->refresh();
        $this->assertSame('recovered', $record->status);
        $this->assertNotNull($record->recovered_at);
        $this->assertNotNull($record->recovered_order_id);
        $this->assertSame('133.99', $record->recovered_revenue);
        $this->assertSame('pending', $unrelated->fresh()->status);
        $this->assertNull($unrelated->fresh()->recovered_order_id);
        $this->assertSame(
            1,
            DB::table('marketing_events')->where('event_name', 'abandoned_cart_recovered')->count(),
        );
    }

    private function record(
        array $items,
        ?User $user = null,
        string $status = 'pending',
        mixed $expiresAt = null,
        ?string $email = null,
    ): AbandonedCartRecord {
        return AbandonedCartRecord::query()->create([
            'user_id' => $user?->id,
            'session_id' => $this->cartSession('abandoned-'.Str::random(10)),
            'email' => $email ?? $user?->email ?? 'recovery@example.test',
            'cart_snapshot' => ['items' => $items, 'subtotal' => 125],
            'cart_total' => 125,
            'items_count' => collect($items)->sum('quantity'),
            'last_cart_activity_at' => now()->subHours(2),
            'recovery_token' => Str::random(64),
            'recovery_token_expires_at' => $expiresAt ?? now()->addDay(),
            'status' => $status,
        ]);
    }

    private function snapshotLine(
        int $quantity = 1,
        bool $isGift = false,
        ?int $promotionId = null,
    ): array {
        return [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'is_gift' => $isGift,
            'promotion_id' => $promotionId,
            'unit_price' => $isGift ? 0 : 125,
            'total_price' => $isGift ? 0 : 125 * $quantity,
        ];
    }

    private function cart(string $name, ?User $user = null): Cart
    {
        return Cart::query()->create([
            'session_id' => $this->cartSession($name),
            'user_id' => $user?->id,
            'customer_email' => $user?->email,
            'status' => 'active',
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function populatedCart(string $kind): Cart
    {
        $cart = $this->cart("populated-recovery-{$kind}");

        if (in_array($kind, ['paid', 'gift'], true)) {
            $cart->items()->create([
                'product_id' => $this->product->id,
                'quantity' => 1,
                'is_gift' => $kind === 'gift',
                'unit_price' => $kind === 'gift' ? 0 : 125,
                'total_price' => $kind === 'gift' ? 0 : 125,
            ]);
        }

        if ($kind === 'bundle') {
            $bundle = ProductBundle::query()->create([
                'name' => 'Recovery target bundle',
                'slug' => 'recovery-target-bundle-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'type' => 'fixed_bundle',
                'pricing_type' => 'fixed_price',
                'fixed_price' => 50,
            ]);
            $cart->bundleItems()->create([
                'product_bundle_id' => $bundle->id,
                'selected_items' => [],
                'quantity' => 1,
                'unit_price' => 50,
                'total_price' => 50,
            ]);
        }

        if ($kind === 'coupon') {
            $cart->update(['coupon_code' => 'KEEP-ME']);
        }

        return $cart->fresh();
    }

    private function cartState(Cart $cart): array
    {
        return [
            'status' => $cart->status,
            'user_id' => $cart->user_id,
            'customer_email' => $cart->customer_email,
            'coupon_code' => $cart->coupon_code,
            'items' => $cart->items()->orderBy('id')->get()->map->only([
                'product_id',
                'quantity',
                'is_gift',
                'promotion_id',
                'unit_price',
                'total_price',
            ])->all(),
            'bundles' => $cart->bundleItems()->orderBy('id')->get()->map->only([
                'product_bundle_id',
                'selected_items',
                'quantity',
                'unit_price',
                'total_price',
            ])->all(),
        ];
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
