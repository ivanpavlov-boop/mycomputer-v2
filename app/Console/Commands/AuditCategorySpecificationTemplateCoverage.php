<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\Products\CategorySpecificationTemplateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AuditCategorySpecificationTemplateCoverage extends Command
{
    protected $signature = 'product-attributes:audit-category-template-coverage
        {--limit=50 : Maximum number of category rows to display}
        {--only-missing : Show only categories without an effective template}
        {--format=table : Output format: table, json, or csv}
        {--include-empty : Include categories with no products}';

    protected $description = 'Read-only audit of category specification template coverage.';

    public function __construct(
        private readonly CategorySpecificationTemplateResolver $templateResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error('Unsupported format. Use table, json, or csv.');

            return self::FAILURE;
        }

        $rows = $this->coverageRows();
        $summary = $this->summary($rows);
        $displayRows = $this->displayRows($rows);
        $payload = [
            'summary' => $summary,
            'rows' => $displayRows->values()->all(),
            'top_missing_template_categories' => $summary['top_missing_template_categories'],
            'suggested_next_product_family_templates' => $summary['suggested_next_product_family_templates'],
        ];

        return match ($format) {
            'json' => $this->renderJson($payload),
            'csv' => $this->renderCsv($displayRows),
            default => $this->renderTable($displayRows, $summary),
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function coverageRows(): Collection
    {
        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->orderBy('id')
            ->get();

        return $categories
            ->filter(fn (Category $category): bool => (bool) $this->option('include-empty') || (int) $category->products_count > 0)
            ->map(function (Category $category): array {
                $template = $this->templateResolver->resolve($category);
                $family = $this->suggestFamily($category);

                return [
                    'category_id' => (int) $category->id,
                    'category_name' => (string) $category->name,
                    'category_slug' => (string) $category->slug,
                    'parent_category' => $category->parent?->name,
                    'products_count' => (int) $category->products_count,
                    'direct_category_product_attributes_count' => $template->directAttributeCount(),
                    'inherited_category_product_attributes_count' => $template->inheritedAttributeCount(),
                    'total_effective_expected_attributes_count' => $template->effectiveAttributeCount(),
                    'coverage_status' => $template->status,
                    'suggested_product_family' => $family,
                    'suggested_next_action' => $this->suggestAction($template->status, $family),
                ];
            })
            ->values();
    }

    private function suggestAction(string $coverageStatus, string $family): string
    {
        return match ($coverageStatus) {
            'direct_template' => 'keep',
            'inherited_template' => 'map to parent template',
            default => $family === 'unknown' ? 'needs manual classification' : 'create template',
        };
    }

    private function suggestFamily(Category $category): string
    {
        $haystack = $this->familySearchText($category);

        foreach ($this->familyKeywords() as $family => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $family;
                }
            }
        }

        return 'unknown';
    }

    private function familySearchText(Category $category): string
    {
        $parts = [];
        $current = $category;
        $guard = 0;

        while ($current !== null && $guard < 20) {
            $parts[] = (string) $current->slug;
            $parts[] = (string) $current->name;
            $current = $current->parent;
            $guard++;
        }

        return strtolower(Str::ascii(implode(' ', $parts)));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function familyKeywords(): array
    {
        return [
            'laptops' => ['laptop', 'laptops', 'laptopi', 'notebook', 'notebooks', 'noutbuk', 'noutbuci', 'noutbutsi'],
            'monitors' => ['monitor', 'monitors', 'monitori', 'display', 'displays'],
            'processors/cpu' => ['processor', 'processors', 'procesor', 'procesori', 'cpu', 'cpus'],
            'memory/ram' => ['memory', 'ram', 'ddr', 'dimm', 'sodimm', 'pamet'],
            'storage/ssd/hdd' => ['storage', 'ssd', 'hdd', 'nvme', 'disk', 'disks', 'diskove', 'drive', 'drives'],
            'gpu/video cards' => ['gpu', 'graphics', 'video-card', 'video-cards', 'videokarta', 'videokarti'],
            'motherboards' => ['motherboard', 'motherboards', 'mainboard', 'mainboards', 'dunni', 'dynni', 'platki'],
            'power supplies' => ['power-supply', 'power-supplies', 'psu', 'zahranvane', 'zahranvaniya'],
            'cases' => ['case', 'cases', 'kutiya', 'kutii'],
            'cables' => ['cable', 'cables', 'kabel', 'kabeli'],
            'peripherals' => ['peripheral', 'peripherals', 'keyboard', 'keyboards', 'mouse', 'mice', 'klaviaturi', 'mishki'],
            'printers' => ['printer', 'printers', 'printeri', 'print'],
            'cameras/security' => ['camera', 'cameras', 'kamera', 'kameri', 'security', 'nablyudenie'],
            'networking' => ['network', 'networking', 'router', 'routers', 'switch', 'switches', 'mrezha', 'mrezhovi'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $directRows = $rows->where('coverage_status', 'direct_template');
        $inheritedRows = $rows->where('coverage_status', 'inherited_template');
        $missingRows = $rows->where('coverage_status', 'no_template');
        $suggestedFamilies = $missingRows
            ->where('suggested_product_family', '!=', 'unknown')
            ->groupBy('suggested_product_family')
            ->map(fn (Collection $familyRows): array => [
                'categories_count' => $familyRows->count(),
                'products_count' => (int) $familyRows->sum('products_count'),
            ])
            ->sortByDesc('products_count')
            ->all();

        return [
            'total_categories_checked' => $rows->count(),
            'categories_with_products' => $rows->filter(fn (array $row): bool => (int) $row['products_count'] > 0)->count(),
            'categories_with_direct_templates' => $directRows->count(),
            'categories_with_inherited_templates' => $inheritedRows->count(),
            'categories_without_templates' => $missingRows->count(),
            'products_covered_by_direct_templates' => (int) $directRows->sum('products_count'),
            'products_covered_by_inherited_templates' => (int) $inheritedRows->sum('products_count'),
            'products_without_templates' => (int) $missingRows->sum('products_count'),
            'top_missing_template_categories' => $missingRows
                ->sortByDesc('products_count')
                ->take(10)
                ->values()
                ->all(),
            'suggested_next_product_family_templates' => $suggestedFamilies,
            'records_changed' => [
                'products' => 0,
                'supplier_products' => 0,
                'categories' => 0,
                'category_product_attributes' => 0,
                'product_attributes' => 0,
                'attribute_values' => 0,
                'product_attribute_values' => 0,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function displayRows(Collection $rows): Collection
    {
        $displayRows = $this->option('only-missing')
            ? $rows->where('coverage_status', 'no_template')
            : $rows;

        $limit = max(1, (int) ($this->option('limit') ?: 50));

        return $displayRows
            ->take($limit)
            ->values();
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
     */
    private function renderCsv(Collection $rows): int
    {
        $headers = [
            'category_id',
            'category_name',
            'category_slug',
            'parent_category',
            'products_count',
            'direct_category_product_attributes_count',
            'inherited_category_product_attributes_count',
            'total_effective_expected_attributes_count',
            'coverage_status',
            'suggested_product_family',
            'suggested_next_action',
        ];

        $this->line(implode(',', $headers));

        foreach ($rows as $row) {
            $this->line(implode(',', array_map(fn (string $header): string => $this->csvValue($row[$header] ?? ''), $headers)));
        }

        return self::SUCCESS;
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     */
    private function renderTable(Collection $rows, array $summary): int
    {
        $this->info('Category specification template coverage audit');
        $this->table([
            'ID',
            'Category',
            'Slug',
            'Parent',
            'Products',
            'Direct',
            'Inherited',
            'Effective',
            'Status',
            'Family',
            'Next action',
        ], $rows->map(fn (array $row): array => [
            $row['category_id'],
            $row['category_name'],
            $row['category_slug'],
            $row['parent_category'] ?? '-',
            $row['products_count'],
            $row['direct_category_product_attributes_count'],
            $row['inherited_category_product_attributes_count'],
            $row['total_effective_expected_attributes_count'],
            $row['coverage_status'],
            $row['suggested_product_family'],
            $row['suggested_next_action'],
        ])->all());

        $this->line('Total categories checked: '.$summary['total_categories_checked']);
        $this->line('Categories with products: '.$summary['categories_with_products']);
        $this->line('Categories with direct templates: '.$summary['categories_with_direct_templates']);
        $this->line('Categories with inherited templates: '.$summary['categories_with_inherited_templates']);
        $this->line('Categories without templates: '.$summary['categories_without_templates']);
        $this->line('Products covered by direct templates: '.$summary['products_covered_by_direct_templates']);
        $this->line('Products covered by inherited templates: '.$summary['products_covered_by_inherited_templates']);
        $this->line('Products without templates: '.$summary['products_without_templates']);
        $this->line('Top missing-template categories by product count:');

        if ($summary['top_missing_template_categories'] === []) {
            $this->line('- none');
        } else {
            foreach ($summary['top_missing_template_categories'] as $row) {
                $this->line(sprintf(
                    '- %s (%s): %d products, family %s, action %s',
                    $row['category_name'],
                    $row['category_slug'],
                    $row['products_count'],
                    $row['suggested_product_family'],
                    $row['suggested_next_action'],
                ));
            }
        }

        $this->line('Suggested next product-family templates:');

        if ($summary['suggested_next_product_family_templates'] === []) {
            $this->line('- none');
        } else {
            foreach ($summary['suggested_next_product_family_templates'] as $family => $stats) {
                $this->line(sprintf('- %s: %d categories, %d products', $family, $stats['categories_count'], $stats['products_count']));
            }
        }

        $this->line('products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('categories changed: 0');
        $this->line('category_product_attributes changed: 0');
        $this->line('product_attributes changed: 0');
        $this->line('attribute_values changed: 0');
        $this->line('product_attribute_values changed: 0');

        return self::SUCCESS;
    }
}
