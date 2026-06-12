<?php

namespace App\Console\Commands;

use App\Jobs\PullStockFromErpJob;
use App\Models\ErpProvider;
use App\Services\Erp\ErpService;
use Illuminate\Console\Command;

class ErpPullStock extends Command
{
    protected $signature = 'erp:pull-stock {provider? : ERP provider code}';

    protected $description = 'Queue stock synchronization from the configured ERP provider.';

    public function handle(ErpService $erp): int
    {
        $provider = $this->argument('provider')
            ? ErpProvider::query()->where('code', $this->argument('provider'))->firstOrFail()
            : $erp->activeProvider();

        if (! $provider) {
            $this->warn('No active ERP provider configured.');

            return self::SUCCESS;
        }

        $syncJob = $erp->createSyncJob('pull', 'stock', 0, ['provider' => $provider->code], $provider);
        PullStockFromErpJob::dispatch($syncJob->id);

        $this->info("Queued ERP stock sync job #{$syncJob->id}.");

        return self::SUCCESS;
    }
}
