<?php

namespace App\Console\Commands;

use App\Services\Suppliers\AsbisPostApplyVerificationService;
use Illuminate\Console\Command;

class AuditAsbisPostApplyVerification extends Command
{
    protected $signature = 'suppliers:audit-asbis-post-apply-verification
        {--supplier= : Required ASBIS supplier id, slug, or exact company name}
        {--product-list= : Required local ProductList.xml path unless --product-list-fixture is used}
        {--price-avail= : Required local PriceAvail.xml path unless --price-avail-fixture is used}
        {--product-list-fixture= : Local ProductList.xml fixture path}
        {--price-avail-fixture= : Local PriceAvail.xml fixture path}
        {--product-key=ProductCode : ProductList join key}
        {--price-key=WIC : PriceAvail join key}
        {--expected-product-list-sha256= : Expected ProductList SHA-256}
        {--expected-price-avail-sha256= : Expected PriceAvail SHA-256}
        {--expected-candidate-sha256= : Expected v2 candidate set SHA-256}
        {--expected-ready-count= : Expected v2 candidate count}
        {--expected-asbis-staged-count= : Expected ASBIS supplier_products count}
        {--expected-total-staged-count= : Expected total supplier_products count}
        {--format=table : Output format: table or json}
        {--summary-only : Suppress row-level issue samples}
        {--sample-limit=20 : Maximum diagnostic sample count}
        {--issue-sample-limit=20 : Maximum issue sample count}';

    protected $description = 'Verify a completed local ASBIS staging apply without writing data; read-only only.';

    public function handle(AsbisPostApplyVerificationService $verification): int
    {
        $payload = $verification->run([
            'supplier' => $this->option('supplier'),
            'product_list' => $this->option('product-list'),
            'price_avail' => $this->option('price-avail'),
            'product_list_fixture' => $this->option('product-list-fixture'),
            'price_avail_fixture' => $this->option('price-avail-fixture'),
            'product_key' => $this->option('product-key'),
            'price_key' => $this->option('price-key'),
            'expected_product_list_sha256' => $this->option('expected-product-list-sha256'),
            'expected_price_avail_sha256' => $this->option('expected-price-avail-sha256'),
            'expected_candidate_sha256' => $this->option('expected-candidate-sha256'),
            'expected_ready_count' => $this->option('expected-ready-count'),
            'expected_asbis_staged_count' => $this->option('expected-asbis-staged-count'),
            'expected_total_staged_count' => $this->option('expected-total-staged-count'),
            'format' => strtolower((string) ($this->option('format') ?: 'table')),
            'summary_only' => (bool) $this->option('summary-only'),
            'sample_limit' => (int) ($this->option('sample-limit') ?: 20),
            'issue_sample_limit' => (int) ($this->option('issue-sample-limit') ?: 20),
        ]);

        if (strtolower((string) $this->option('format')) === 'json') {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['verification_passed'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        return $this->renderTable($payload);
    }

    /** @param array<string, mixed> $payload */
    private function renderTable(array $payload): int
    {
        $this->info('ASBIS post-apply verification');
        $this->line('Read-only verification. No repair, apply, sync, remote fetch, or protected-table writes are available.');
        $this->table(['Supplier', 'Mode', 'Candidate rows', 'ASBIS staged', 'Total staged', 'Missing SKU', 'Extra SKU', 'Row mismatches', 'Verdict'], [[
            data_get($payload, 'supplier.name', '-'),
            $payload['mode'] ?? 'post_apply_verification',
            $payload['calculated_candidate_count'] ?? 0,
            data_get($payload, 'database_counts.asbis_supplier_products', 0),
            data_get($payload, 'database_counts.total_supplier_products', 0),
            data_get($payload, 'sku_reconciliation.missing_count', 0),
            data_get($payload, 'sku_reconciliation.extra_count', 0),
            data_get($payload, 'row_reconciliation.field_mismatch_counts', []) === [] ? 0 : array_sum(data_get($payload, 'row_reconciliation.field_mismatch_counts', [])),
            $payload['verdict'] ?? 'verification_failed',
        ]]);
        $this->line('Verification passed: '.(($payload['verification_passed'] ?? false) ? 'yes' : 'no'));
        $this->line('Read-only: yes');
        $this->line('Records changed: '.json_encode($payload['records_changed'] ?? [], JSON_UNESCAPED_SLASHES));

        if (($payload['issue_counts'] ?? []) !== []) {
            $this->table(['Issue', 'Count'], collect($payload['issue_counts'])->map(fn (int $count, string $reason): array => [$reason, $count])->all());
        }

        return ($payload['verification_passed'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
