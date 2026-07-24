<?php

namespace App\Console\Commands;

use App\Enums\CartStatus;
use App\Models\Cart;
use Illuminate\Console\Command;

class ExpireStaleCarts extends Command
{
    protected $signature = 'carts:expire-stale
        {--apply : Mark stale active carts as expired}';

    protected $description = 'Preview or apply stale active cart expiration';

    public function handle(): int
    {
        $now = now();
        $staleQuery = Cart::query()
            ->where('status', CartStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
        $wouldChange = (clone $staleQuery)->count();
        $alreadyExpired = Cart::query()->where('status', CartStatus::Expired->value)->count();
        $converted = Cart::query()->where('status', CartStatus::Converted->value)->count();
        $merged = Cart::query()->where('status', CartStatus::Merged->value)->count();
        $apply = (bool) $this->option('apply');
        $changed = 0;

        if ($apply) {
            (clone $staleQuery)
                ->orderBy('id')
                ->chunkById(500, function ($carts) use ($now, &$changed): void {
                    $changed += Cart::query()
                        ->whereKey($carts->pluck('id'))
                        ->where('status', CartStatus::Active->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $now)
                        ->update(['status' => CartStatus::Expired->value]);
                });
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Stale active carts', $wouldChange],
                ['Already expired carts', $alreadyExpired],
                ['Converted carts', $converted],
                ['Merged carts', $merged],
                ['Records that would change', $wouldChange],
                ['Changed carts', $changed],
            ],
        );
        $this->info($apply ? 'Mode: apply' : 'Mode: preview (no writes)');

        return self::SUCCESS;
    }
}
