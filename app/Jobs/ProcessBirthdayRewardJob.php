<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Loyalty\PointsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBirthdayRewardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $userId)
    {
        $this->onQueue('loyalty');
    }

    public function handle(PointsService $points): void
    {
        $user = User::query()->with('profile')->find($this->userId);
        if (! $user?->profile?->birthday) {
            return;
        }

        $points->earn($user, (int) config('loyalty.birthday_bonus_points', 100), 'Birthday bonus points.');
    }
}
