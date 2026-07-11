<?php

namespace App\Console\Commands;

use App\Services\Suppliers\ControlledAsbisDualFeedStagingImportService;
use Illuminate\Console\Command;

class ControlledAsbisDualFeedStagingImport extends Command
{
    protected $signature = 'suppliers:controlled-asbis-dual-feed-staging-import
        {--supplier= : Required ASBIS supplier id, slug, or exact company name}
        {--product-list= : Required local ProductList.xml path unless --product-list-fixture is used}
        {--price-avail= : Required local PriceAvail.xml path unless --price-avail-fixture is used}
        {--product-list-fixture= : Local ProductList.xml fixture path}
        {--price-avail-fixture= : Local PriceAvail.xml fixture path}
        {--product-key=ProductCode : ProductList join key}
        {--price-key=WIC : PriceAvail join key}
        {--format=table : Output format: table or json}
        {--sample-limit=20 : Maximum candidate samples}
        {--issue-sample-limit=20 : Maximum issue samples}
        {--summary-only : Suppress bounded audit samples}
        {--batch-size=500 : Insert batch size, from 1 to 1000}
        {--apply : Opt in to the guarded supplier_products-only transaction}
        {--confirm-supplier= : Required apply confirmation: asbis}
        {--confirm-mode= : Required apply confirmation: create-only}
        {--confirm-write-scope= : Required apply confirmation: supplier_products-only}
        {--expected-product-list-sha256= : Expected ProductList SHA-256}
        {--expected-price-avail-sha256= : Expected PriceAvail SHA-256}
        {--expected-ready-count= : Expected ready_to_create candidate count}
        {--expected-candidate-sha256= : Expected ready_to_create candidate-set SHA-256}
        {--expected-asbis-staged-count= : Expected current ASBIS supplier_products count}';

    protected $description = 'Dry-run-first, create-only ASBIS dual-feed staging import with explicit apply safeguards.';

    public function handle(ControlledAsbisDualFeedStagingImportService $service): int
    {
        $format = strtolower((string) ($this->option('format') ?: 'table'));
        $payload = $service->run([
            'supplier' => $this->option('supplier'),
            'product_list' => $this->option('product-list'),
            'price_avail' => $this->option('price-avail'),
            'product_list_fixture' => $this->option('product-list-fixture'),
            'price_avail_fixture' => $this->option('price-avail-fixture'),
            'product_key' => $this->option('product-key'),
            'price_key' => $this->option('price-key'),
            'format' => $format,
            'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
            'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
            'summary_only' => (bool) $this->option('summary-only'),
            'batch_size' => (int) ($this->option('batch-size') ?: 500),
            'apply' => (bool) $this->option('apply'),
            'confirm_supplier' => $this->option('confirm-supplier'),
            'confirm_mode' => $this->option('confirm-mode'),
            'confirm_write_scope' => $this->option('confirm-write-scope'),
            'expected_product_list_sha256' => $this->option('expected-product-list-sha256'),
            'expected_price_avail_sha256' => $this->option('expected-price-avail-sha256'),
            'expected_ready_count' => $this->option('expected-ready-count'),
            'expected_candidate_sha256' => $this->option('expected-candidate-sha256'),
            'expected_asbis_staged_count' => $this->option('expected-asbis-staged-count'),
        ]);

        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($payload);
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTable(array $payload): void
    {
        $this->info('ASBIS controlled dual-feed staging import');
        $this->line('Dry-run by default. Write scope: supplier_products only; create-only; Catalog Sync is not run.');

        $this->table([
            'Mode', 'Success', 'Feature', 'Can apply', 'Ready CREATE', 'Staged before', 'Staged after',
            'Attempted', 'Inserted', 'Skipped', 'Updated', 'Batches', 'Batch size', 'Transaction',
        ], [[
            $payload['mode'] ?? '-',
            ($payload['success'] ?? false) ? 'yes' : 'no',
            ($payload['feature_enabled'] ?? false) ? 'enabled' : 'disabled',
            ($payload['can_apply'] ?? false) ? 'yes' : 'no',
            $payload['calculated_ready_count'] ?? 0,
            $payload['staged_before'] ?? 0,
            $payload['staged_after'] ?? 0,
            $payload['attempted_insert_count'] ?? 0,
            $payload['inserted_count'] ?? 0,
            $payload['skipped_count'] ?? 0,
            $payload['updated_count'] ?? 0,
            $payload['batches'] ?? 0,
            $payload['batch_size'] ?? 0,
            ($payload['transaction_committed'] ?? false) ? 'committed' : 'not committed',
        ]]);

        $this->line('ProductList SHA-256: '.data_get($payload, 'source_fingerprints.product_list_sha256', '-'));
        $this->line('PriceAvail SHA-256: '.data_get($payload, 'source_fingerprints.price_avail_sha256', '-'));
        $this->line('Candidate SHA-256: '.($payload['ready_to_create_candidate_set_sha256'] ?? '-'));
        $this->line('Candidate schema: '.($payload['candidate_payload_schema_version'] ?? '-'));
        $this->line('Payload schema compatible: '.(($payload['payload_schema_compatible'] ?? false) ? 'yes' : 'no'));
        $this->line('Truncated names: '.data_get($payload, 'payload_schema_compatibility.truncated_name_count', 0));
        $this->line('Maximum original/staged name length: '.data_get($payload, 'payload_schema_compatibility.maximum_original_name_length', 0).'/'.data_get($payload, 'payload_schema_compatibility.maximum_staged_name_length', 0));

        if (($payload['refusal_reasons'] ?? []) !== []) {
            $this->warn('Refusal reasons: '.implode(', ', $payload['refusal_reasons']));
        }

        if (($payload['failure_diagnostics'] ?? null) !== null) {
            $this->warn('Failure diagnostics: '.json_encode($payload['failure_diagnostics'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        foreach (($payload['candidate_samples'] ?? []) as $sample) {
            $this->line(sprintf(
                'Candidate %s | %s | %s %s | %s',
                $sample['supplier_sku'] ?? '-',
                $sample['name'] ?? '-',
                $sample['price'] ?? '-',
                $sample['currency'] ?? '',
                $sample['availability'] ?? '-'
            ));
        }

        foreach (($payload['records_changed'] ?? []) as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
