<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyticsEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $eventName,
        public string $source,
        public array $payload = [],
        public ?int $userId = null,
        public ?string $sessionId = null,
    ) {
        $this->onQueue('analytics');
    }

    public function viaQueue(): string
    {
        return 'analytics';
    }

    public function handle(MarketingEventService $events): void
    {
        $events->log($this->eventName, $this->source, $this->payload, $this->userId ? User::query()->find($this->userId) : null, $this->sessionId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
