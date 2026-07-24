<?php

namespace Tests\Feature;

use App\Jobs\ProcessBirthdayRewardJob;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\RewardVoucher;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Loyalty\PointsService;
use App\Services\Loyalty\RewardEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LoyaltyRewardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_can_be_earned_and_logged(): void
    {
        $user = User::factory()->create();

        app(PointsService::class)->earn($user, 150, 'Manual test award.');

        $this->assertDatabaseHas('loyalty_accounts', [
            'user_id' => $user->id,
            'points_balance' => 150,
            'lifetime_points' => 150,
            'tier' => 'bronze',
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'type' => 'earned',
            'points' => 150,
            'description' => 'Manual test award.',
        ]);
    }

    public function test_tier_is_recalculated_from_lifetime_points(): void
    {
        $user = User::factory()->create();

        app(PointsService::class)->earn($user, 5000, 'Gold threshold.');

        $this->assertSame('gold', $user->loyaltyAccount()->firstOrFail()->tier);
    }

    public function test_reward_can_be_redeemed_for_points(): void
    {
        $user = User::factory()->create();
        $voucher = $this->rewardVoucher(points: 100);
        app(PointsService::class)->earn($user, 250, 'Opening balance.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/redeem', ['reward_id' => $voucher->id])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LOYALTY10')
            ->assertJsonPath('data.redeemed_points', 100);

        $this->assertSame(150, $user->loyaltyAccount()->firstOrFail()->points_balance);
        $this->assertDatabaseHas('voucher_redemptions', [
            'user_id' => $user->id,
            'reward_voucher_id' => $voucher->id,
            'redeemed_points' => 100,
        ]);
    }

    public function test_reward_validation_rejects_expired_voucher(): void
    {
        $user = User::factory()->create();
        $voucher = $this->rewardVoucher([
            'expires_at' => now()->subDay(),
        ]);
        app(PointsService::class)->earn($user, 500, 'Opening balance.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/redeem', ['reward_id' => $voucher->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.reward_id.0', 'Reward voucher has expired.');
    }

    public function test_duplicate_redemption_is_prevented(): void
    {
        $user = User::factory()->create();
        $voucher = $this->rewardVoucher(points: 50);
        app(PointsService::class)->earn($user, 200, 'Opening balance.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/redeem', ['reward_id' => $voucher->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/rewards/redeem', ['reward_id' => $voucher->id])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.reward_id.0', 'Reward voucher already redeemed.');
    }

    public function test_checkout_can_apply_reward_voucher(): void
    {
        $this->seed();

        $user = User::factory()->create(['email' => 'loyalty@example.com']);
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['price' => 100, 'regular_price' => 100, 'promo_price' => null, 'quantity' => 5]);

        app(PointsService::class)->earn($user, 500, 'Opening balance.');
        $voucher = $this->rewardVoucher(points: 100, discount: 20);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', $this->cartSession('loyalty-cart'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', $this->cartSession('loyalty-cart'))
            ->postJson('/api/v1/checkout', array_merge($this->checkoutPayload(), [
                'email' => 'loyalty@example.com',
                'reward_code' => $voucher->code,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.discount_total', '20.00')
            ->assertJsonPath('data.grand_total', '88.99');

        $this->assertSame(400, $user->loyaltyAccount()->firstOrFail()->points_balance);
        $this->assertDatabaseHas('voucher_redemptions', [
            'user_id' => $user->id,
            'reward_voucher_id' => $voucher->id,
            'redeemed_points' => 100,
        ]);
    }

    public function test_birthday_reward_awards_configured_points(): void
    {
        Config::set('loyalty.birthday_bonus_points', 75);
        $user = User::factory()->create();
        UserProfile::query()->create([
            'user_id' => $user->id,
            'birthday' => now()->subYears(30),
            'newsletter_subscribed' => false,
        ]);

        (new ProcessBirthdayRewardJob($user->id))->handle(app(PointsService::class));

        $this->assertSame(75, $user->loyaltyAccount()->firstOrFail()->points_balance);
    }

    public function test_admin_adjustment_cannot_create_negative_balance(): void
    {
        $this->expectExceptionMessage('Point adjustment would create a negative balance.');

        app(PointsService::class)->adjust(User::factory()->create(), -1, 'Invalid adjustment.');
    }

    public function test_expired_points_are_deducted(): void
    {
        $user = User::factory()->create();
        $points = app(PointsService::class);
        $points->earn($user, 80, 'Expiring points.', expiresAt: now()->subMinute());

        $expired = $points->expire($user->loyaltyAccount()->firstOrFail());

        $this->assertSame(80, $expired);
        $this->assertSame(0, $user->loyaltyAccount()->firstOrFail()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'type' => 'expired',
            'points' => -80,
        ]);
    }

    public function test_completed_order_awards_points_once(): void
    {
        $user = User::factory()->create(['email' => 'completed@example.com']);
        $order = Order::query()->create([
            'order_number' => 'ORD-LOYALTY-001',
            'user_id' => $user->id,
            'customer_email' => 'completed@example.com',
            'customer_phone' => '0888123456',
            'customer_name' => 'Ivan Petrov',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 200,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 200,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'shipping_method' => 'address_delivery',
            'shipping_status' => 'delivered',
            'status' => 'completed',
        ]);

        $first = app(RewardEngine::class)->awardForCompletedOrder($order->fresh('user.loyaltyAccount'));
        $second = app(RewardEngine::class)->awardForCompletedOrder($order->fresh('user.loyaltyAccount'));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, LoyaltyAccount::query()->where('user_id', $user->id)->firstOrFail()->transactions()->where('reference_id', $order->id)->count());
    }

    private function rewardVoucher(array $overrides = [], int $points = 100, int $discount = 10): RewardVoucher
    {
        return RewardVoucher::query()->create(array_merge([
            'code' => 'LOYALTY10',
            'title' => '10 EUR discount',
            'points_cost' => $points,
            'discount_type' => 'fixed',
            'discount_value' => $discount,
            'minimum_order_amount' => null,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'usage_limit' => null,
            'usage_count' => 0,
            'is_active' => true,
        ], $overrides));
    }

    private function cartItem(): void
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $product->update(['price' => 100, 'regular_price' => 100, 'promo_price' => null, 'quantity' => 5]);

        $this->withHeader('X-Cart-Session', $this->cartSession('loyalty-cart'))
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();
    }

    private function checkoutPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'client@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia, Bulgaria',
            'shipping_address' => 'Sofia, Bulgaria',
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'address_delivery',
            'shipping_provider' => 'manual',
            'city' => 'Sofia',
            'notes' => 'Please call',
            'terms' => true,
        ];
    }
}
