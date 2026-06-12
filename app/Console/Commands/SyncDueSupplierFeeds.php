<?php

namespace App\Console\Commands;

use App\Jobs\ProcessXmlSupplierFeed;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\XmlMappingTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncDueSupplierFeeds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'suppliers:sync-due-feeds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue XML imports for active supplier feeds that are due for sync.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queued = 0;

        SupplierFeed::query()
            ->where('status', 'active')
            ->where('feed_type', 'xml')
            ->with('supplier')
            ->each(function (SupplierFeed $feed) use (&$queued): void {
                if (! $this->isDue($feed)) {
                    return;
                }

                $template = XmlMappingTemplate::query()
                    ->where(function ($query) use ($feed): void {
                        $query
                            ->where('supplier_id', $feed->supplier_id)
                            ->orWhereNull('supplier_id');
                    })
                    ->where('is_active', true)
                    ->latest('supplier_id')
                    ->first();

                if (! $template) {
                    $this->warn("No XML mapping template found for feed [{$feed->feed_name}].");

                    return;
                }

                $job = ImportJob::query()->create([
                    'supplier_id' => $feed->supplier_id,
                    'supplier_feed_id' => $feed->id,
                    'xml_mapping_template_id' => $template->id,
                    'type' => 'xml',
                    'mode' => 'scheduled',
                    'status' => 'pending',
                ]);

                ProcessXmlSupplierFeed::dispatch($job->id);
                $queued++;
            });

        $this->info("Queued {$queued} supplier XML import job(s).");

        return self::SUCCESS;
    }

    protected function isDue(SupplierFeed $feed): bool
    {
        if ($feed->update_interval === 'manual') {
            return false;
        }

        if ($feed->last_sync_at === null) {
            return true;
        }

        $nextRun = match ($feed->update_interval) {
            'hourly' => $feed->last_sync_at->copy()->addHour(),
            '6h' => $feed->last_sync_at->copy()->addHours(6),
            '12h' => $feed->last_sync_at->copy()->addHours(12),
            'daily' => $feed->last_sync_at->copy()->addDay(),
            default => Carbon::now()->addCentury(),
        };

        return $nextRun->isPast();
    }
}
