<?php

namespace App\Console\Commands;

use App\Services\Email\EmailMarketingService;
use Illuminate\Console\Command;

class ProcessAbandonedCarts extends Command
{
    protected $signature = 'carts:process-abandoned';

    protected $description = 'Process due abandoned cart recovery emails.';

    public function handle(EmailMarketingService $emailMarketing): int
    {
        $processed = $emailMarketing->processDueAbandonedCarts();

        $this->info("Processed {$processed} abandoned cart recovery emails.");

        return self::SUCCESS;
    }
}
