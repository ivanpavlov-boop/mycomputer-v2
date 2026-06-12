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
        $threshold = $this->option('threshold') !== null ? (int) $this->option('threshold') : null;
        $detected = $emailMarketing->detectAbandonedCarts($threshold);

        $this->info("Detected {$detected} abandoned carts.");

        return self::SUCCESS;
    }
}
