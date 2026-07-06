<?php

namespace App\Console\Commands;

use App\Models\SupplierCategoryMapping;
use App\Services\Taxonomy\SupplierCategoryDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiscoverSupplierCategoryMappings extends Command
{
    protected $signature = 'supplier-categories:discover-mappings
        {--limit=50 : Maximum number of mapping candidates to process}
        {--supplier= : Limit to supplier id, slug, or exact company name}
        {--only-unmapped : Process only supplier categories without a mapping record}
        {--apply : Create missing pending supplier category mapping records}
        {--format=table : Output format: table or json}';

    protected $description = 'Preview or create pending supplier category mapping records without applying them to products.';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Unsupported format. Use table or json.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = app(SupplierCategoryDiscoveryService::class)
            ->candidates(
                supplier: filled($this->option('supplier')) ? (string) $this->option('supplier') : null,
                onlyUnmapped: (bool) $this->option('only-unmapped'),
            )
            ->take(max(1, (int) ($this->option('limit') ?: 50)))
            ->values();

        $stats = $apply
            ? DB::transaction(fn (): array => $this->process($rows, true))
            : $this->process($rows, false);

        $payload = [
            'summary' => $stats,
            'rows' => $rows->values()->all(),
        ];

        return $format === 'json'
            ? $this->renderJson($payload)
            : $this->renderTable($rows, $stats, $apply);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function process(Collection $rows, bool $apply): array
    {
        $stats = [
            'mapping_candidates_found' => $rows->count(),
            'mappings_to_create' => 0,
            'mappings_created' => 0,
            'mappings_already_present' => 0,
            'mappings_skipped' => 0,
            'records_changed' => $this->changedRecords(),
        ];

        foreach ($rows as $row) {
            if ($row['mapping_id'] !== null) {
                $stats['mappings_already_present']++;

                continue;
            }

            if ($row['supplier_category_name'] === '(empty)') {
                $stats['mappings_skipped']++;

                continue;
            }

            $stats['mappings_to_create']++;

            if (! $apply) {
                continue;
            }

            SupplierCategoryMapping::query()->create([
                'supplier_id' => $row['supplier_id'],
                'supplier_key' => $row['supplier_key'],
                'supplier_name' => $row['supplier_name'],
                'supplier_category_name' => $row['supplier_category_name'],
                'supplier_category_slug' => $row['supplier_category_slug'],
                'supplier_category_path' => $row['supplier_category_path'],
                'supplier_category_external_id' => $row['supplier_category_external_id'],
                'supplier_category_hash' => $row['supplier_category_hash'],
                'canonical_product_family_id' => $row['canonical_product_family_id'],
                'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
                'confidence' => $row['confidence'],
                'match_reason' => $row['match_reason'],
                'metadata' => [
                    'phase' => '9C.5.5',
                    'product_count_at_discovery' => $row['product_count'],
                    'source' => 'supplier_products.category_name',
                ],
            ]);

            $stats['mappings_created']++;
        }

        if ($apply) {
            $stats['records_changed']['supplier_category_mappings'] = $stats['mappings_created'];
        }

        return $stats;
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
     * @param  array<string, mixed>  $stats
     */
    private function renderTable(Collection $rows, array $stats, bool $apply): int
    {
        $this->info($apply ? 'Supplier category mappings discovered and created as pending review.' : 'Dry-run only. No records were changed.');

        $this->table([
            'Supplier',
            'Supplier category',
            'Products',
            'Existing status',
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

        $this->line('Mapping candidates found: '.$stats['mapping_candidates_found']);
        $this->line(($apply ? 'Mappings created: ' : 'Mappings to create: ').($apply ? $stats['mappings_created'] : $stats['mappings_to_create']));
        $this->line('Mappings already present: '.$stats['mappings_already_present']);
        $this->line('Mappings skipped: '.$stats['mappings_skipped']);
        $this->zeroChangeCounters($stats['records_changed']);

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
            'supplier_category_mappings' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $recordsChanged
     */
    private function zeroChangeCounters(array $recordsChanged): void
    {
        foreach ($recordsChanged as $table => $count) {
            $this->line($table.' changed: '.$count);
        }
    }
}
