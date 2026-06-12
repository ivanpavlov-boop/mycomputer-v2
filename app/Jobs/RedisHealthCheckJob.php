<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RedisHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $key)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Cache::put($this->key, 'processed', now()->addMinutes(5));
    }
}
