<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierImportOrchestrator;
use App\Services\Suppliers\SupplierImportScheduleService;
use Illuminate\Console\Command;

class RunScheduledSupplierImports extends Command
{
    protected $signature = 'suppliers:run-scheduled-imports';

    protected $description = 'Dispatch due supplier imports using supplier schedule settings.';

    public function handle(
        SupplierImportScheduleService $schedules,
        SupplierImportOrchestrator $orchestrator,
    ): int {
        $dueSuppliers = $schedules->dueSuppliers();

        if ($dueSuppliers->isEmpty()) {
            $this->info('No scheduled supplier imports are due.');

            return self::SUCCESS;
        }

        foreach ($dueSuppliers as $supplier) {
            $run = $orchestrator->dispatch($supplier, 'scheduled');

            if ($run->status !== 'skipped') {
                $supplier->update([
                    'next_import_at' => $schedules->nextRunAt($supplier, now()),
                ]);
            }

            $this->line("Dispatched supplier import for {$supplier->company_name} as run #{$run->id}.");
        }

        $this->info("Dispatched {$dueSuppliers->count()} scheduled supplier imports.");

        return self::SUCCESS;
    }
}
