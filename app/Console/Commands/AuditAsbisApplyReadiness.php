<?php

namespace App\Console\Commands;

use App\Services\Suppliers\AsbisApplyReadinessAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AuditAsbisApplyReadiness extends Command
{
    protected $signature = 'suppliers:audit-asbis-apply-readiness
        {--supplier= : Required ASBIS supplier id, slug, or exact company name}
        {--product-list= : Required local ProductList.xml path unless --product-list-fixture is used}
        {--price-avail= : Required local PriceAvail.xml path unless --price-avail-fixture is used}
        {--product-list-fixture= : Local ProductList.xml fixture path}
        {--price-avail-fixture= : Local PriceAvail.xml fixture path}
        {--product-key=ProductCode : ProductList join key}
        {--price-key=WIC : PriceAvail join key}
        {--format=table : Output format: table or json}
        {--sample-limit=20 : Maximum samples per readiness section}
        {--issue-sample-limit=20 : Maximum issue samples}
        {--show-ready-samples : Show bounded ready samples in table output}
        {--show-manual-review-samples : Show bounded manual-review samples in table output}
        {--show-unmatched-samples : Show bounded ProductList-only and PriceAvail-only samples in table output}
        {--show-overlap-samples : Show bounded cross-supplier overlap samples in table output}
        {--show-field-map : Show detected field maps in table output}
        {--summary-only : Suppress all row-level samples}';

    protected $description = 'Audit complete local ASBIS dual feeds for future staging apply readiness without writing data.';

    public function handle(AsbisApplyReadinessAuditService $audit): int
    {
        $payload = $audit->run([
            'supplier' => filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            'product_list' => filled($this->option('product-list')) ? (string) $this->option('product-list') : null,
            'price_avail' => filled($this->option('price-avail')) ? (string) $this->option('price-avail') : null,
            'product_list_fixture' => filled($this->option('product-list-fixture')) ? (string) $this->option('product-list-fixture') : null,
            'price_avail_fixture' => filled($this->option('price-avail-fixture')) ? (string) $this->option('price-avail-fixture') : null,
            'product_key' => filled($this->option('product-key')) ? (string) $this->option('product-key') : null,
            'price_key' => filled($this->option('price-key')) ? (string) $this->option('price-key') : null,
            'format' => strtolower((string) ($this->option('format') ?: 'table')),
            'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
            'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
            'summary_only' => (bool) $this->option('summary-only'),
            'mode' => 'apply_readiness_audit',
            'full_file' => true,
        ]);

        if (strtolower((string) $this->option('format')) === 'json') {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        return $this->renderTable($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload): int
    {
        $this->info('ASBIS controlled staging apply-readiness audit');
        $this->line('Advisory and read-only. No apply option, remote fetch, jobs, supplier_products writes, Catalog Sync, or catalog changes.');

        if (! ($payload['success'] ?? false)) {
            $this->error((string) data_get($payload, 'issues.0.message', 'ASBIS readiness audit failed.'));

            return self::FAILURE;
        }

        $summary = $payload['summary'] ?? [];
        $this->table([
            'Supplier', 'Mode', 'Parser', 'ProductList', 'PriceAvail', 'ProductCode', 'WIC', 'Joined',
            'Ready create', 'Ready update', 'Warnings', 'Manual', 'Blocked', 'Product-only', 'Price-only',
            'Dup ProductCode', 'Dup WIC', 'Dup EAN', 'Overlaps', 'Elapsed', 'Peak memory', 'Verdict', 'Safety',
        ], [[
            $summary['supplier_name'] ?? '-',
            $summary['mode'] ?? '-',
            $summary['parser_mode'] ?? '-',
            $summary['product_list_rows'] ?? 0,
            $summary['price_avail_rows'] ?? 0,
            $summary['unique_product_code'] ?? 0,
            $summary['unique_wic'] ?? 0,
            $summary['joined_rows'] ?? 0,
            $summary['ready_to_create'] ?? 0,
            $summary['ready_to_update'] ?? 0,
            $summary['ready_with_warning'] ?? 0,
            $summary['manual_review'] ?? 0,
            $summary['blocked'] ?? 0,
            $summary['product_only_rows'] ?? 0,
            $summary['price_only_rows'] ?? 0,
            $summary['duplicate_product_code'] ?? 0,
            $summary['duplicate_wic'] ?? 0,
            $summary['duplicate_ean'] ?? 0,
            $summary['cross_supplier_matches'] ?? 0,
            $summary['elapsed_seconds'] ?? 0,
            $summary['peak_memory_bytes'] ?? 0,
            $summary['verdict'] ?? '-',
            $summary['safety_status'] ?? '-',
        ]]);

        $this->line('ProductList SHA-256: '.data_get($payload, 'source_fingerprints.product_list_sha256', '-'));
        $this->line('PriceAvail SHA-256: '.data_get($payload, 'source_fingerprints.price_avail_sha256', '-'));
        $this->line('Apply candidates: '.data_get($payload, 'readiness.apply_candidate_count', 0));
        $this->line('Apply blockers: '.data_get($payload, 'readiness.apply_blocker_count', 0));

        if ((bool) $this->option('show-field-map')) {
            $this->renderKeyValues('ProductList field map', data_get($payload, 'detected_product_fields.normalized_field_map', []));
            $this->renderKeyValues('PriceAvail field map', data_get($payload, 'detected_price_fields.normalized_field_map', []));
        }

        if ((bool) $this->option('show-ready-samples')) {
            $this->renderSamples('Ready samples', $payload['ready_samples'] ?? []);
        }

        if ((bool) $this->option('show-manual-review-samples')) {
            $this->renderSamples('Manual-review samples', $payload['manual_review_samples'] ?? []);
        }

        if ((bool) $this->option('show-unmatched-samples')) {
            $this->renderSamples('ProductList-only samples', $payload['unmatched_product_samples'] ?? []);
            $this->renderSamples('PriceAvail-only samples', $payload['unmatched_price_samples'] ?? []);
        }

        if ((bool) $this->option('show-overlap-samples')) {
            $this->renderSamples('Cross-supplier overlap samples', $payload['overlaps'] ?? []);
        }

        foreach (($payload['records_changed'] ?? []) as $table => $count) {
            $this->line($table.' changed: '.$count);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function renderKeyValues(string $title, array $values): void
    {
        $this->newLine();
        $this->info($title);
        $this->table(['Field', 'Source'], collect($values)->map(fn (mixed $value, string $key): array => [$key, $value ?? '-'])->values()->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSamples(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->info($title);
        $this->table(['SKU', 'Name', 'Price', 'Availability', 'State', 'Issues'], collect($rows)->map(fn (array $row): array => [
            $row['supplier_sku'] ?? '-',
            Str::limit((string) ($row['name'] ?? '-'), 50),
            trim((string) ($row['price'] ?? '-').' '.(string) ($row['currency'] ?? '')),
            $row['availability'] ?? '-',
            $row['readiness_state'] ?? '-',
            implode(', ', [...($row['issues'] ?? []), ...($row['warnings'] ?? [])]),
        ])->all());
    }
}
