<?php

namespace App\Console\Commands;

use App\Services\Suppliers\Onboarding\OperationalSupplierOfferEvidenceBundleReader;
use App\Services\Suppliers\Onboarding\OperationalSupplierOfferLifecyclePreviewService;
use Illuminate\Console\Command;
use Throwable;

final class PreviewOperationalSupplierOfferLifecycle extends Command
{
    protected $signature = 'suppliers:preview-apcom-offer-lifecycle
        {--supplier= : Required supplier key; only apcom is approved}
        {--evidence= : Required immutable local JSON evidence bundle}
        {--expected-sha256= : Required SHA-256 of the evidence bundle}
        {--evaluated-at= : Required timezone-aware timestamp, for example 2026-08-12T12:00:00+03:00}
        {--format=table : Output format: table or json}
        {--limit=20 : Bounded hashed sample limit from 1 through 100}';

    protected $description = 'Evaluate the APCOM missing-offer lifecycle from local immutable evidence; CLI-only, deterministic, read-only, and non-persistent.';

    public function handle(
        OperationalSupplierOfferEvidenceBundleReader $reader,
        OperationalSupplierOfferLifecyclePreviewService $service,
    ): int {
        $format = strtolower(trim((string) $this->option('format')));
        if (! in_array($format, ['json', 'table'], true)) {
            $this->error('invalid_output_format');

            return self::FAILURE;
        }
        if (strtolower(trim((string) $this->option('supplier'))) !== 'apcom') {
            $this->error('supplier_scope_mismatch');

            return self::FAILURE;
        }

        try {
            $bundle = $reader->read(
                (string) $this->option('evidence'),
                (string) $this->option('expected-sha256'),
            );
            $evaluatedAt = $reader->parseEvaluatedAt((string) $this->option('evaluated-at'));
            $report = $service->preview($bundle, $evaluatedAt, (int) $this->option('limit'));
        } catch (Throwable $exception) {
            $this->error($this->safeErrorCode($exception));

            return self::FAILURE;
        }

        $payload = $report->toArray();
        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('APCOM operational missing-offer lifecycle preview');
        $this->line('Read-only evaluation. No import, persistence, Catalog Sync, link, lifecycle, visibility, job, or schedule action was run.');
        $this->table(['Metric', 'Value'], [
            ['Supplier', $payload['supplier']],
            ['Evaluated at', $payload['evaluated_at']],
            ['Qualified snapshots', data_get($payload, 'counts.qualified_snapshot_count', 0)],
            ['Frozen snapshots', data_get($payload, 'counts.frozen_snapshot_count', 0)],
            ['Confirmed missing offers', data_get($payload, 'counts.confirmed_missing_count', 0)],
            ['Potential CREATE candidates', data_get($payload, 'counts.source_only_potential_create_count', 0)],
            ['Manual review recommendations', data_get($payload, 'recommendation_counts.manual_review', 0)],
            ['Records changed', array_sum((array) $payload['records_changed'])],
            ['Jobs dispatched', $payload['dispatched_jobs']],
        ]);

        return self::SUCCESS;
    }

    private function safeErrorCode(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return preg_match('/^[a-z0-9_]+$/', $message) === 1 ? $message : 'operational_preview_failed_safely';
    }
}
