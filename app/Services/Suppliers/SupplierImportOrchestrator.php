<?php

namespace App\Services\Suppliers;

use App\Jobs\RunSupplierImportJob;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAttribute;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use App\Services\Products\ProductSyncService;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SupplierImportOrchestrator
{
    public function __construct(
        private readonly SupplierImportScheduleService $schedule,
        private readonly SupplierImportSafetyService $safety,
        private readonly SupplierImportReportService $reports,
        private readonly SupplierImportNotificationService $notifications,
    ) {}

    public function dispatch(Supplier $supplier, string $triggerType = 'manual', bool $force = false): SupplierImportRun
    {
        if (! $force && $this->hasRunningImport($supplier)) {
            return SupplierImportRun::query()->create([
                'supplier_id' => $supplier->id,
                'trigger_type' => $triggerType,
                'import_type' => 'xml',
                'status' => 'skipped',
                'warning_count' => 1,
                'warnings' => ['Supplier import already running.'],
                'finished_at' => now(),
            ]);
        }

        $run = SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'trigger_type' => $triggerType,
            'import_type' => 'xml',
            'status' => 'pending',
        ]);

        RunSupplierImportJob::dispatch($run->id, $force);

        return $run;
    }

    public function execute(SupplierImportRun $run, bool $force = false): SupplierImportRun
    {
        $run->loadMissing('supplier');
        $supplier = $run->supplier;
        $lock = Cache::lock('supplier_import:'.$supplier->id, 3600);

        if (! $force && ! $lock->get()) {
            $run->update([
                'status' => 'skipped',
                'warning_count' => 1,
                'warnings' => ['Supplier import lock is already held.'],
                'finished_at' => now(),
            ]);

            return $this->reports->generate($run->fresh());
        }

        if ($force) {
            $lock->forceRelease();
            $lock->get();
        }

        try {
            return $this->executeLocked($run->fresh(['supplier']));
        } finally {
            optional($lock)->release();
        }
    }

    private function executeLocked(SupplierImportRun $run): SupplierImportRun
    {
        $supplier = $run->supplier;
        $startedAt = now();
        $run->update(['status' => 'running', 'started_at' => $startedAt]);

        try {
            $feed = $this->feed($supplier);

            if (! $feed) {
                return $this->finish($run, 'skipped', ['No active supplier feed found.']);
            }

            if (! in_array($feed->feed_type, ['xml', 'csv'], true)) {
                return $this->finish($run, 'failed', [], ['Only XML and CSV supplier imports are executable by the scheduler today.']);
            }

            $importJob = ImportJob::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_feed_id' => $feed->id,
                'xml_mapping_template_id' => null,
                'type' => $feed->feed_type,
                'mode' => $run->trigger_type,
                'status' => 'pending',
            ]);

            if ($feed->feed_type === 'xml') {
                $template = $this->mappingTemplate($feed);

                if (! $template) {
                    return $this->finish($run, 'failed', [], ['No active XML mapping template found.']);
                }

                $importJob->update(['xml_mapping_template_id' => $template->id]);
            }

            $run->update([
                'supplier_feed_id' => $feed->id,
                'import_job_id' => $importJob->id,
                'import_type' => $feed->feed_type,
            ]);

            if ($feed->feed_type === 'csv') {
                app(SupplierCsvFeedImportService::class)->import($feed, $importJob);
            } else {
                app(XmlImportEngine::class)->import($importJob);
            }
            $importJob->refresh();

            $run->fill([
                'products_seen' => (int) $importJob->processed_rows,
                'products_failed' => (int) $importJob->failed_rows,
            ])->save();

            $this->collectImportMetrics($run->refresh(), $startedAt);
            $decision = $this->safety->evaluate($supplier, $run->fresh());

            if ($decision['errors'] !== []) {
                return $this->finish($run->fresh(), 'failed', $decision['warnings'], $decision['errors']);
            }

            $syncStats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            if (! $decision['block_sync']) {
                $syncStats = $this->syncImportedProducts($supplier, $startedAt);
            }

            $status = $decision['warnings'] === [] ? 'completed' : 'completed_with_warnings';

            $run->update([
                'products_created' => $syncStats['created'],
                'products_updated' => $syncStats['updated'],
                'products_skipped' => $syncStats['skipped'],
            ]);

            return $this->finish($run->fresh(), $status, $decision['warnings']);
        } catch (Throwable $exception) {
            return $this->finish($run->fresh(), 'failed', [], [$exception->getMessage()]);
        }
    }

    private function finish(SupplierImportRun $run, string $status, array $warnings = [], array $errors = []): SupplierImportRun
    {
        $finishedAt = now();
        $duration = $run->started_at ? $run->started_at->diffInSeconds($finishedAt) : 0;

        $run->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_seconds' => $duration,
            'warning_count' => count($warnings),
            'error_count' => count($errors),
            'warnings' => $warnings,
            'errors' => $errors,
        ]);

        $supplier = $run->supplier()->first();
        $supplier?->update([
            'last_import_at' => $finishedAt,
            'next_import_at' => $supplier ? $this->schedule->nextRunAt($supplier, $finishedAt) : null,
        ]);

        $run = $this->reports->generate($run->fresh());
        $this->notifications->notifyIfNeeded($run->supplier, $run);

        return $run;
    }

    private function collectImportMetrics(SupplierImportRun $run, $startedAt): void
    {
        $supplierId = $run->supplier_id;

        $run->update([
            'products_out_of_stock' => SupplierProduct::query()
                ->where('supplier_id', $supplierId)
                ->where('received_at', '>=', $startedAt)
                ->where(fn ($query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0))
                ->count(),
            'attributes_mapped' => SupplierProductAttribute::query()
                ->where('supplier_id', $supplierId)
                ->where('created_at', '>=', $startedAt)
                ->where('status', 'mapped')
                ->count(),
            'attributes_unmapped' => SupplierProductAttribute::query()
                ->where('supplier_id', $supplierId)
                ->where('created_at', '>=', $startedAt)
                ->whereIn('status', ['unmapped', 'needs_review'])
                ->count(),
            'availability_mapped' => SupplierProduct::query()
                ->where('supplier_id', $supplierId)
                ->where('received_at', '>=', $startedAt)
                ->whereNotNull('availability_status_id')
                ->count(),
            'availability_unmapped' => SupplierProduct::query()
                ->where('supplier_id', $supplierId)
                ->where('received_at', '>=', $startedAt)
                ->whereNull('availability_status_id')
                ->whereNotNull('external_availability_status')
                ->count(),
        ]);
    }

    private function syncImportedProducts(Supplier $supplier, $startedAt): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $productIds = [];

        SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->where('received_at', '>=', $startedAt)
            ->where('status', 'new')
            ->chunkById(100, function ($products) use (&$stats, &$productIds): void {
                foreach ($products as $supplierProduct) {
                    $log = app(ProductSyncService::class)->sync($supplierProduct);

                    if ($log->action === 'created') {
                        $stats['created']++;
                    } elseif ($log->action === 'updated') {
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }

                    if ($log->product_id) {
                        $productIds[] = $log->product_id;
                    }
                }
            });

        ProductSyncLog::query()
            ->whereIn('product_id', array_unique($productIds))
            ->where('created_at', '>=', $startedAt)
            ->count();

        if ($productIds !== []) {
            Product::query()->whereIn('id', array_unique($productIds))->get()->searchable();
        }

        return $stats;
    }

    private function hasRunningImport(Supplier $supplier): bool
    {
        return SupplierImportRun::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    private function feed(Supplier $supplier): ?SupplierFeed
    {
        return $supplier->feeds()
            ->where('status', 'active')
            ->orderByRaw("case feed_type when 'xml' then 1 when 'csv' then 2 else 3 end")
            ->first();
    }

    private function mappingTemplate(SupplierFeed $feed): ?XmlMappingTemplate
    {
        return XmlMappingTemplate::query()
            ->where(function ($query) use ($feed): void {
                $query->where('supplier_id', $feed->supplier_id)->orWhereNull('supplier_id');
            })
            ->where('is_active', true)
            ->latest('supplier_id')
            ->first();
    }
}
