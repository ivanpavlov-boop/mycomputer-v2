<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\Products\LegacyProductAttributeValueReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileLegacyProductAttributeValues extends Command
{
    protected $signature = 'product-attributes:reconcile-legacy-values
        {--apply : Persist safe proposals for one explicit product}
        {--dry-run : Preview changes without writing anything}
        {--sku= : Limit to one product SKU}
        {--product-id= : Limit to one product ID}
        {--limit= : Limit products scanned}
        {--only-missing-quality : Limit to products with missing specification quality}
        {--category= : Limit to one category slug}
        {--attribute= : Limit to one legacy source attribute code, slug, or name}';

    protected $description = 'Dry-run or safely apply legacy out-of-category product attribute value reconciliation.';

    public function __construct(private readonly LegacyProductAttributeValueReconciliationService $reconciliation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $sku = $this->option('sku');
        $productId = $this->option('product-id');

        if ($apply && blank($sku) && blank($productId)) {
            $this->error('Refusing unrestricted apply. Use --apply with --sku=SKU or --product-id=ID.');

            return self::FAILURE;
        }

        if ($apply && filled($sku) && filled($productId)) {
            $this->error('Use --apply with either --sku or --product-id, not both.');

            return self::FAILURE;
        }

        $products = $this->products();
        $stats = $this->emptyStats();
        $allProposals = collect();

        foreach ($products as $product) {
            $stats['products_scanned']++;
            $result = $this->reconciliation->preview($product, [
                'attribute' => $this->option('attribute'),
                'only_missing_quality' => (bool) $this->option('only-missing-quality'),
            ]);

            if ($result['legacy_values_found'] === 0 && $result['proposals']->isEmpty()) {
                continue;
            }

            $stats['legacy_values_found'] += $result['legacy_values_found'];
            $allProposals = $allProposals->merge($result['proposals']);
        }

        $this->summarizeProposals($stats, $allProposals);

        if ($apply) {
            $applyStats = DB::transaction(fn (): array => $this->reconciliation->apply($allProposals));
            $stats['created_rows'] = $applyStats['created'];
            $stats['skipped_rows'] = $applyStats['skipped'];
        }

        $this->printSummary($stats, $allProposals, $apply);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(): Collection
    {
        $query = Product::query()
            ->with([
                'category.parent',
                'attributeValues.attribute',
                'attributeValues.value',
            ])
            ->orderBy('id');

        if (filled($this->option('sku'))) {
            $query->where('sku', $this->option('sku'));
        }

        if (filled($this->option('product-id'))) {
            $query->whereKey((int) $this->option('product-id'));
        }

        if (filled($this->option('category'))) {
            $categoryIds = Category::query()
                ->where('slug', $this->option('category'))
                ->pluck('id');

            $query->whereIn('category_id', $categoryIds);
        }

        $limit = $this->safeLimit();

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, Product> $products */
        $products = $query->get();

        return $products;
    }

    private function safeLimit(): ?int
    {
        if (blank($this->option('limit'))) {
            return null;
        }

        return max(1, min((int) $this->option('limit'), 5000));
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'products_scanned' => 0,
            'legacy_values_found' => 0,
            'proposals_generated' => 0,
            'proposals_skipped' => 0,
            'proposals_needing_manual_review' => 0,
            'target_already_filled' => 0,
            'product_attribute_values_to_create' => 0,
            'created_rows' => 0,
            'skipped_rows' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $stats
     * @param  Collection<int, array<string, mixed>>  $proposals
     */
    private function summarizeProposals(array &$stats, Collection $proposals): void
    {
        $stats['proposals_generated'] = $proposals->count();
        $stats['product_attribute_values_to_create'] = $proposals
            ->where('action', LegacyProductAttributeValueReconciliationService::ACTION_WOULD_CREATE)
            ->count();
        $stats['target_already_filled'] = $proposals
            ->where('action', LegacyProductAttributeValueReconciliationService::ACTION_TARGET_ALREADY_FILLED)
            ->count();
        $stats['proposals_needing_manual_review'] = $proposals
            ->filter(fn (array $proposal): bool => in_array($proposal['action'], [
                LegacyProductAttributeValueReconciliationService::ACTION_NEEDS_MANUAL_REVIEW,
                LegacyProductAttributeValueReconciliationService::ACTION_SKIPPED_AMBIGUOUS,
                LegacyProductAttributeValueReconciliationService::ACTION_MISSING_TARGET_ATTRIBUTE,
                LegacyProductAttributeValueReconciliationService::ACTION_MISSING_TARGET_OPTION,
            ], true))
            ->count();
        $stats['proposals_skipped'] = $proposals->count() - $stats['product_attribute_values_to_create'];
    }

    /**
     * @param  array<string, int>  $stats
     * @param  Collection<int, array<string, mixed>>  $proposals
     */
    private function printSummary(array $stats, Collection $proposals, bool $apply): void
    {
        $this->info($apply
            ? 'Legacy product attribute value reconciliation applied.'
            : 'Dry-run only. No records were changed.');

        $this->line('Products scanned: '.$stats['products_scanned']);
        $this->line('Legacy values found: '.$stats['legacy_values_found']);
        $this->line('Proposals generated: '.$stats['proposals_generated']);
        $this->line('Proposals skipped: '.$stats['proposals_skipped']);
        $this->line('Proposals needing manual review: '.$stats['proposals_needing_manual_review']);
        $this->line('Target already filled: '.$stats['target_already_filled']);

        if ($apply) {
            $this->line('Created rows: '.$stats['created_rows']);
            $this->line('Skipped rows: '.$stats['skipped_rows']);
            $this->line('legacy values deleted: 0');
            $this->line('legacy values changed: 0');
        } else {
            $this->line('product_attribute_values to create: '.$stats['product_attribute_values_to_create']);
            $this->line('product_attribute_values changed: 0');
        }

        $this->line('products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('category_product_attributes changed: 0');
        $this->line('product_attributes created: 0');
        $this->line('attribute_values created: 0');

        foreach ($proposals as $proposal) {
            $this->line(sprintf(
                '- product #%d %s "%s" | source #%d %s/%s "%s" | target %s/%s "%s" | action=%s | confidence=%s | reason=%s',
                $proposal['product_id'],
                $proposal['product_sku'] ?: '-',
                $proposal['product_name'] ?: '-',
                $proposal['source_attribute_id'],
                $proposal['source_attribute_code'] ?: '-',
                $proposal['source_attribute_name'] ?: '-',
                $proposal['source_value'] ?: '-',
                $proposal['target_code'] ?: '-',
                $proposal['target_name'] ?: '-',
                $proposal['parsed_value'] ?: '-',
                $proposal['action'],
                $proposal['confidence'],
                $proposal['reason'],
            ));
        }
    }
}
