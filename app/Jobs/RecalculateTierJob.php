<?php

namespace App\Jobs;

use App\Models\LoyaltyAccount;
use App\Services\Loyalty\PointsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateTierJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $loyaltyAccountId)
    {
        $this->onQueue('loyalty');
    }

    public function handle(PointsService $points): void
    {
        if ($account = LoyaltyAccount::query()->find($this->loyaltyAccountId)) {
            $points->recalculateTier($account);
        }
    }
}
