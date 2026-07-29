<?php

namespace App\Console\Commands;

use App\Services\Email\EmailMarketingService;
use Illuminate\Console\Command;

class DetectAbandonedCarts extends Command
{
    protected $signature = 'carts:detect-abandoned {--threshold= : Override inactivity threshold in minutes}';

    protected $description = 'Detect inactive carts and create abandoned cart recovery records.';

    public function handle(EmailMarketingService $emailMarketing): int
    {
        if (config('commerce.abandoned_cart_recovery.enabled') !== true) {
            $this->warn('Abandoned cart recovery is disabled; no records were changed.');

            return self::SUCCESS;
        }

        $threshold = $this->option('threshold') !== null ? (int) $this->option('threshold') : null;
        $detected = $emailMarketing->detectAbandonedCarts($threshold);

        $this->info("Detected {$detected} abandoned carts.");

        return self::SUCCESS;
    }
}
