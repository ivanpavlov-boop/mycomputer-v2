<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\RewardEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AwardPointsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public ?int $orderId = null, public ?int $userId = null, public ?string $source = null)
    {
        $this->onQueue('loyalty');
    }

    public function handle(RewardEngine $rewards): void
    {
        if ($this->orderId && ($order = Order::query()->with('user.loyaltyAccount')->find($this->orderId))) {
            $rewards->awardForCompletedOrder($order);

            return;
        }

        if ($this->userId && $this->source && ($user = User::query()->find($this->userId))) {
            $rewards->awardSource($user, $this->source, "Loyalty points from {$this->source}");
        }
    }
}
