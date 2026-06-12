<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\RewardVoucher;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LoyaltyService
{
    public function __construct(
        public readonly PointsService $points,
        public readonly TierCalculationService $tiers,
        public readonly VoucherService $vouchers,
        public readonly RewardEngine $rewards,
    ) {}

    public function account(User $user): LoyaltyAccount
    {
        return $this->points->account($user)->load('transactions');
    }

    public function applyVoucherDiscount(User $user, string $code, float $subtotal): array
    {
        $voucher = RewardVoucher::query()->where('code', $code)->firstOrFail();

        try {
            $this->vouchers->validate($voucher, $subtotal);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['reward_code' => $exception->getMessage()]);
        }

        if ($this->points->account($user)->points_balance < $voucher->points_cost) {
            throw ValidationException::withMessages(['reward_code' => 'Insufficient loyalty points.']);
        }

        return [
            'voucher' => $voucher,
            'discount' => $this->vouchers->discount($voucher, $subtotal),
        ];
    }

    public function attachVoucherToOrder(User $user, string $code, Order $order): void
    {
        $this->vouchers->attachOrder($user, $code, $order);
    }
}
