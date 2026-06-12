<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyAccount;

class TierCalculationService
{
    public function tierFor(int $lifetimePoints): string
    {
        $tier = 'bronze';

        foreach (config('loyalty.tiers', []) as $name => $config) {
            if ($lifetimePoints >= (int) ($config['threshold'] ?? 0)) {
                $tier = $name;
            }
        }

        return $tier;
    }

    public function nextTier(LoyaltyAccount $account): ?array
    {
        foreach (config('loyalty.tiers', []) as $name => $config) {
            $threshold = (int) ($config['threshold'] ?? 0);
            if ($threshold > $account->lifetime_points) {
                return [
                    'tier' => $name,
                    'threshold' => $threshold,
                    'remaining_points' => $threshold - $account->lifetime_points,
                    'progress_percentage' => min(100, (int) floor(($account->lifetime_points / $threshold) * 100)),
                ];
            }
        }

        return null;
    }
}
