<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LoyaltyNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $userId, public string $type, public array $data = [])
    {
        $this->onQueue('loyalty');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        if ($user = User::query()->find($this->userId)) {
            $emailMarketing->queue($user->email, $this->type, ['user' => $user] + $this->data);
        }
    }
}
