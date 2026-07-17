<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\SupplierOfferLifecyclePreviewService;
use Illuminate\Console\Command;

final class PreviewSupplierOfferLifecyclePolicy extends Command
{
    protected $signature = 'suppliers:preview-offer-lifecycle-policy
        {--supplier=apcom : Synthetic supplier policy key}
        {--scenario=all : Bounded synthetic scenario set}
        {--format=json : Output format: json or table}';

    protected $description = 'Emit a deterministic, synthetic, read-only supplier-offer lifecycle policy preview.';

    public function __construct(private readonly SupplierOfferLifecyclePreviewService $previewService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $report = $this->previewService->preview(
                (string) $this->option('supplier'),
                (string) $this->option('scenario'),
            )->toArray();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->option('format') !== 'table') {
            $this->error('The format option must be json or table.');

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $report['mode']],
            ['Supplier', $report['supplier']],
            ['Verdict', $report['verdict']],
            ['Approval gate', data_get($report, 'approval_gate.gate_status')],
            ['Synthetic scenarios', count((array) $report['synthetic_scenarios'])],
            ['Records changed', array_sum((array) $report['records_changed'])],
        ]);

        return self::SUCCESS;
    }
}
