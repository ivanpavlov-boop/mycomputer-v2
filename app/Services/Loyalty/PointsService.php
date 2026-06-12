<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PointsService
{
    public function __construct(private readonly TierCalculationService $tiers) {}

    public function account(User $user): LoyaltyAccount
    {
        return LoyaltyAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['points_balance' => 0, 'lifetime_points' => 0, 'tier' => 'bronze'],
        );
    }

    public function earn(User $user, int $points, string $description, ?Model $reference = null, ?\DateTimeInterface $expiresAt = null): LoyaltyTransaction
    {
        if ($points <= 0) {
            throw new RuntimeException('Earned points must be positive.');
        }

        return DB::transaction(function () use ($user, $points, $description, $reference, $expiresAt): LoyaltyTransaction {
            $this->account($user);
            $account = LoyaltyAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $account->increment('points_balance', $points);
            $account->increment('lifetime_points', $points);

            $this->recalculateTier($account->refresh());

            return $account->transactions()->create([
                'type' => 'earned',
                'points' => $points,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public function redeem(User $user, int $points, string $description, ?Model $reference = null): LoyaltyTransaction
    {
        if ($points <= 0) {
            throw new RuntimeException('Redeemed points must be positive.');
        }

        return DB::transaction(function () use ($user, $points, $description, $reference): LoyaltyTransaction {
            $this->account($user);
            $account = LoyaltyAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ($account->points_balance < $points) {
                throw new RuntimeException('Insufficient loyalty points.');
            }

            $account->decrement('points_balance', $points);

            return $account->transactions()->create([
                'type' => 'redeemed',
                'points' => -$points,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'description' => $description,
            ]);
        });
    }

    public function adjust(User $user, int $points, string $description): LoyaltyTransaction
    {
        return DB::transaction(function () use ($user, $points, $description): LoyaltyTransaction {
            $this->account($user);
            $account = LoyaltyAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $nextBalance = $account->points_balance + $points;

            if ($nextBalance < 0) {
                throw new RuntimeException('Point adjustment would create a negative balance.');
            }

            $account->update([
                'points_balance' => $nextBalance,
                'lifetime_points' => $points > 0 ? $account->lifetime_points + $points : $account->lifetime_points,
            ]);

            $this->recalculateTier($account->refresh());

            return $account->transactions()->create([
                'type' => 'adjusted',
                'points' => $points,
                'description' => $description,
            ]);
        });
    }

    public function expire(LoyaltyAccount $account): int
    {
        return DB::transaction(function () use ($account): int {
            $expired = $account->transactions()
                ->where('type', 'earned')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->sum('points');

            $expired = min((int) $expired, $account->points_balance);
            if ($expired <= 0) {
                return 0;
            }

            $account->decrement('points_balance', $expired);
            $account->transactions()->create([
                'type' => 'expired',
                'points' => -$expired,
                'description' => 'Expired loyalty points.',
            ]);

            return $expired;
        });
    }

    public function recalculateTier(LoyaltyAccount $account): bool
    {
        $tier = $this->tiers->tierFor($account->lifetime_points);
        if ($account->tier === $tier) {
            return false;
        }

        $account->update(['tier' => $tier]);

        return true;
    }
}
