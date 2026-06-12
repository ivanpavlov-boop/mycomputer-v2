<?php

namespace App\Services\Loyalty;

use App\Models\Order;
use App\Models\User;

class RewardEngine
{
    public function __construct(private readonly PointsService $points) {}

    public function awardForCompletedOrder(Order $order): int
    {
        if (! $order->user || $order->status !== 'completed') {
            return 0;
        }

        if ($order->user->loyaltyAccount?->transactions()
            ->where('type', 'earned')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->exists()) {
            return 0;
        }

        $multiplier = (float) config("loyalty.tiers.{$order->user->loyaltyAccount?->tier}.multiplier", 1);
        $points = (int) floor((float) $order->grand_total * (int) config('loyalty.points_per_bgn', 1) * $multiplier);

        if ($points > 0) {
            $this->points->earn($order->user, $points, "Points from completed order {$order->order_number}", $order);
        }

        return $points;
    }

    public function awardSource(User $user, string $source, string $description): int
    {
        $points = (int) config("loyalty.sources.{$source}", 0);

        if ($points > 0) {
            $this->points->earn($user, $points, $description);
        }

        return $points;
    }
}
