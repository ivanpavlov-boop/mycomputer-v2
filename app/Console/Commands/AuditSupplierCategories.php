<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\SupplierCategoryDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditSupplierCategories extends Command
{
    protected $signature = 'supplier-categories:audit
        {--limit=50 : Maximum number of rows to display}
        {--supplier= : Limit to supplier id, slug, or exact company name}
        {--format=table : Output format: table or json}
        {--only-unmapped : Show only supplier categories without a mapping record}
        {--include-empty : Include empty supplier category values}';

    protected $description = 'Read-only audit of staged supplier categories and mapping status.';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $rows = app(SupplierCategoryDiscoveryService::class)->candidates(
            supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
            onlyUnmapped: (bool) $this->option('only-unmapped'),
            includeEmpty: (bool) $this->option('include-empty'),
        );
        $limitedRows = $this->limitedRows($rows);
        $payload = [
            'summary' => $this->summary($rows),
            'rows' => $limitedRows->values()->all(),
        ];

        return $format === 'json'
            ? $this->renderJson($payload)
            : $this->renderTable($limitedRows, $payload['summary']);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function limitedRows(Collection $rows): Collection
    {
        return $rows->take(max(1, (int) ($this->option('limit') ?: 50)))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        return [
            'supplier_category_candidates' => $rows->count(),
            'unmapped_supplier_categories' => $rows->whereNull('mapping_id')->count(),
            'mapped_supplier_categories' => $rows->whereNotNull('mapping_id')->count(),
            'pending_review' => $rows->where('mapping_status', 'pending_review')->count(),
            'approved' => $rows->where('mapping_status', 'approved')->count(),
            'ignored' => $rows->where('mapping_status', 'ignored')->count(),
            'rejected' => $rows->where('mapping_status', 'rejected')->count(),
            'records_changed' => $this->changedRecords(),
            'checked_sources' => [
                'supplier_products.category_name',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderJson(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     */
    private function renderTable(Collection $rows, array $summary): int
    {
        $this->info('Supplier category audit');

        if ($rows->isEmpty()) {
            $this->warn('No supplier category data found in supplier_products.category_name for the selected filters.');
        }

        $this->table([
            'Supplier',
            'Supplier category',
            'Products',
            'Mapping status',
            'Suggested family',
            'Confidence',
            'Next action',
        ], $rows->map(fn (array $row): array => [
            $row['supplier_name'],
            $row['supplier_category_name'],
            $row['product_count'],
            $row['mapping_status'] ?? 'unmapped',
            $row['suggested_canonical_family'],
            $row['confidence'],
            $row['next_action'],
        ])->all());

        $this->line('Supplier category candidates: '.$summary['supplier_category_candidates']);
        $this->line('Unmapped supplier categories: '.$summary['unmapped_supplier_categories']);
        $this->line('Mapped supplier categories: '.$summary['mapped_supplier_categories']);
        $this->zeroChangeCounters();

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function changedRecords(): array
    {
        return [
            'products' => 0,
            'supplier_products' => 0,
            'categories' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
        ];
    }

    private function zeroChangeCounters(): void
    {
        foreach ($this->changedRecords() as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
