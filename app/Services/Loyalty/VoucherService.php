<?php

namespace App\Services\Loyalty;

use App\Models\Order;
use App\Models\RewardVoucher;
use App\Models\User;
use App\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoucherService
{
    public function __construct(private readonly PointsService $points) {}

    public function validate(RewardVoucher $voucher, float $orderSubtotal = 0): void
    {
        if (! $voucher->is_active) {
            throw new RuntimeException('Reward voucher is inactive.');
        }

        if ($voucher->starts_at && $voucher->starts_at->isFuture()) {
            throw new RuntimeException('Reward voucher is not active yet.');
        }

        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            throw new RuntimeException('Reward voucher has expired.');
        }

        if ($voucher->usage_limit !== null && $voucher->usage_count >= $voucher->usage_limit) {
            throw new RuntimeException('Reward voucher usage limit reached.');
        }

        if ($voucher->minimum_order_amount !== null && $orderSubtotal < (float) $voucher->minimum_order_amount) {
            throw new RuntimeException('Minimum order amount is not met.');
        }
    }

    public function discount(RewardVoucher $voucher, float $subtotal): float
    {
        $discount = $voucher->discount_type === 'percentage'
            ? $subtotal * ((float) $voucher->discount_value / 100)
            : (float) $voucher->discount_value;

        return round(min($discount, $subtotal), 2);
    }

    public function redeem(User $user, RewardVoucher $voucher, ?Order $order = null): VoucherRedemption
    {
        return DB::transaction(function () use ($user, $voucher, $order): VoucherRedemption {
            $voucher = RewardVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            $this->validate($voucher, $order ? (float) $order->subtotal : 0);

            if (VoucherRedemption::query()->where('user_id', $user->id)->where('reward_voucher_id', $voucher->id)->exists()) {
                throw new RuntimeException('Reward voucher already redeemed.');
            }

            $redemption = VoucherRedemption::query()->create([
                'user_id' => $user->id,
                'reward_voucher_id' => $voucher->id,
                'order_id' => $order?->id,
                'code' => $voucher->code,
                'redeemed_points' => $voucher->points_cost,
            ]);

            $voucher->increment('usage_count');
            $this->points->redeem($user, $voucher->points_cost, "Redeemed reward voucher {$voucher->code}", $redemption);

            return $redemption;
        });
    }

    public function attachOrder(User $user, string $code, Order $order): ?VoucherRedemption
    {
        $redemption = VoucherRedemption::query()
            ->where('user_id', $user->id)
            ->where('code', $code)
            ->whereNull('order_id')
            ->latest()
            ->first();

        if (! $redemption) {
            return null;
        }

        $redemption->update(['order_id' => $order->id]);

        return $redemption;
    }
}
