<?php

namespace App\Console\Commands;

use App\Jobs\RedisHealthCheckJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisHealth extends Command
{
    protected $signature = 'redis:health {--dispatch-test-job : Dispatch a lightweight Redis queue test job}';

    protected $description = 'Validate Redis connectivity and Laravel Redis-backed cache, session, rate limit and queue readiness.';

    /**
     * @var array<int, array{status: string, check: string, detail: string}>
     */
    private array $results = [];

    public function handle(): int
    {
        $this->checkClient();
        $this->checkConnection();
        $this->checkCache();
        $this->checkSession();
        $this->checkRateLimiter();
        $this->checkQueue();

        if ($this->option('dispatch-test-job')) {
            $this->dispatchQueueJob();
        }

        $this->renderResults();

        return collect($this->results)->contains('status', 'FAIL') ? self::FAILURE : self::SUCCESS;
    }

    private function checkClient(): void
    {
        $client = (string) config('database.redis.client');

        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            $this->result('FAIL', 'Redis PHP client', 'REDIS_CLIENT=phpredis but the redis PHP extension is not loaded.');

            return;
        }

        $this->result('PASS', 'Redis PHP client', "Using {$client}.");
    }

    private function checkConnection(): void
    {
        try {
            $pong = Redis::connection('default')->ping();
            $this->result('PASS', 'Redis connectivity', 'PING returned '.json_encode($pong).'.');
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Redis connectivity', $exception->getMessage());
        }
    }

    private function checkCache(): void
    {
        if (config('cache.default') !== 'redis') {
            $this->result('WARNING', 'Cache store', 'CACHE_STORE is not redis.');

            return;
        }

        try {
            $key = 'redis-health:cache:'.uniqid();
            Cache::put($key, 'ok', now()->addMinutes(5));
            $value = Cache::get($key);
            Cache::forget($key);

            $this->result($value === 'ok' ? 'PASS' : 'FAIL', 'Cache read/write', $value === 'ok' ? 'Redis cache round trip succeeded.' : 'Redis cache value mismatch.');
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Cache read/write', $exception->getMessage());
        }
    }

    private function checkSession(): void
    {
        if (config('session.driver') !== 'redis') {
            $this->result('WARNING', 'Session driver', 'SESSION_DRIVER is not redis.');

            return;
        }

        $connection = config('session.connection') ?: config('database.redis.client');
        $this->result('PASS', 'Session driver', "SESSION_DRIVER=redis using connection [{$connection}].");
    }

    private function checkRateLimiter(): void
    {
        try {
            $key = 'redis-health-rate-limit:'.uniqid();
            $allowed = RateLimiter::attempt($key, 1, fn (): bool => true, 60);

            $this->result($allowed ? 'PASS' : 'FAIL', 'Rate limiter', $allowed ? 'Rate limiter cache operation succeeded.' : 'Rate limiter rejected first attempt.');
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Rate limiter', $exception->getMessage());
        }
    }

    private function checkQueue(): void
    {
        if (config('queue.default') !== 'redis') {
            $this->result('WARNING', 'Queue connection', 'QUEUE_CONNECTION is not redis.');

            return;
        }

        try {
            $size = Queue::connection('redis')->size('default');
            $this->result('PASS', 'Queue readiness', "Redis queue [default] is reachable; current size {$size}.");
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Queue readiness', $exception->getMessage());
        }
    }

    private function dispatchQueueJob(): void
    {
        if (config('queue.default') !== 'redis') {
            $this->result('WARNING', 'Queue dispatch', 'Skipped because QUEUE_CONNECTION is not redis.');

            return;
        }

        try {
            $key = 'redis-health:job:'.uniqid();
            RedisHealthCheckJob::dispatch($key);
            $this->result('PASS', 'Queue dispatch', "Dispatched RedisHealthCheckJob. Run a worker and check cache key [{$key}] for processing.");
        } catch (Throwable $exception) {
            $this->result('FAIL', 'Queue dispatch', $exception->getMessage());
        }
    }

    private function result(string $status, string $check, string $detail): void
    {
        $this->results[] = compact('status', 'check', 'detail');
    }

    private function renderResults(): void
    {
        $this->table(['Status', 'Check', 'Detail'], $this->results);

        $summary = collect($this->results)->countBy('status');
        $this->line(sprintf(
            'Summary: PASS=%d WARNING=%d FAIL=%d',
            $summary->get('PASS', 0),
            $summary->get('WARNING', 0),
            $summary->get('FAIL', 0),
        ));
    }
}
