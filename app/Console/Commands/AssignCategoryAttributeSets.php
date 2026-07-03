<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\ProductAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignCategoryAttributeSets extends Command
{
    protected $signature = 'product-attributes:assign-category-sets
        {--apply : Persist missing category attribute assignments}
        {--dry-run : Preview changes without writing anything}
        {--set= : Limit to one starter category set key}
        {--category= : Limit to one existing category slug or set alias}
        {--list : List configured category attribute sets}';

    protected $description = 'Preview or apply controlled category-to-product-attribute assignments.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->listCategorySets();

            return self::SUCCESS;
        }

        $setKey = $this->normalizedSetOption('set');

        if ($setKey !== null && ! array_key_exists($setKey, $this->categorySets())) {
            $this->error(sprintf('Unknown category attribute set "%s". Use --list to see available sets.', $setKey));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $stats = $apply
            ? DB::transaction(fn (): array => $this->processCategorySets(true))
            : $this->processCategorySets(false);

        $this->info($apply
            ? 'Category attribute sets applied.'
            : 'Dry-run only. No records were changed.');

        $this->line('Category sets considered: '.$stats['sets_considered']);
        $this->line('Categories found: '.$stats['categories_found']);
        $this->line('Categories skipped: '.$stats['categories_skipped']);
        $this->line('Assignments to create: '.$stats['assignments_to_create']);
        $this->line('Assignments already present: '.$stats['assignments_existing']);
        $this->line('Attributes missing: '.$stats['attributes_missing']);
        $this->line('Categories created: 0');
        $this->line('Products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('product_attributes created: 0');
        $this->line('attribute_values created: 0');
        $this->line('product_attribute_values created: 0');
        $this->line('Product category assignments changed: 0');

        foreach ($stats['messages'] as $message) {
            $this->warn($message);
        }

        return self::SUCCESS;
    }

    private function listCategorySets(): void
    {
        $this->info('Configured category attribute sets:');

        foreach ($this->categorySets() as $key => $set) {
            $this->line(sprintf('- %s', $key));
            $this->line('  aliases: '.implode(', ', $set['aliases']));
            $this->line('  attributes: '.implode(', ', $set['attributes']));
        }
    }

    /**
     * @return array{sets_considered: int, categories_found: int, categories_skipped: int, assignments_to_create: int, assignments_existing: int, attributes_missing: int, messages: array<int, string>}
     */
    private function processCategorySets(bool $apply): array
    {
        $stats = $this->emptyStats();
        $sets = $this->filteredCategorySets();

        if ($sets === []) {
            $stats['messages'][] = 'No category attribute sets matched the selected filters.';

            return $stats;
        }

        foreach ($sets as $key => $set) {
            $stats['sets_considered']++;

            $category = $this->resolveCategory($key, $set);

            if (! $category) {
                $stats['categories_skipped']++;
                $stats['messages'][] = sprintf(
                    'Skipped category set "%s": none of these category slugs exist: %s.',
                    $key,
                    implode(', ', $this->categoryCandidates($key, $set)),
                );

                continue;
            }

            $stats['categories_found']++;

            foreach ($set['attributes'] as $index => $attributeCode) {
                $resolved = $this->resolveAttribute($attributeCode);
                $attribute = $resolved['attribute'];

                if (! $attribute) {
                    $stats['attributes_missing']++;
                    $stats['messages'][] = sprintf(
                        'Missing attribute for set "%s" and category "%s": %s.',
                        $key,
                        $category->slug,
                        $attributeCode,
                    );

                    continue;
                }

                $this->recordSlugCodeMismatch($stats, $key, $attributeCode, $resolved);

                $exists = CategoryProductAttribute::query()
                    ->where('category_id', $category->id)
                    ->where('product_attribute_id', $attribute->id)
                    ->exists();

                if ($exists) {
                    $stats['assignments_existing']++;

                    continue;
                }

                $stats['assignments_to_create']++;

                if (! $apply) {
                    continue;
                }

                CategoryProductAttribute::query()->create([
                    'category_id' => $category->id,
                    'product_attribute_id' => $attribute->id,
                    'is_required' => false,
                    'is_filterable' => (bool) $attribute->is_filterable,
                    'is_visible_on_product' => (bool) $attribute->is_visible_on_product,
                    'is_comparable' => (bool) $attribute->is_comparable,
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return array<string, array{aliases: array<int, string>, attributes: array<int, string>}>
     */
    private function filteredCategorySets(): array
    {
        $sets = $this->categorySets();
        $setKey = $this->normalizedSetOption('set');
        $category = $this->normalizedCategoryOption();

        if ($setKey !== null) {
            $sets = [$setKey => $sets[$setKey]];
        }

        if ($category === null) {
            return $sets;
        }

        return array_filter(
            $sets,
            fn (array $set, string $key): bool => $key === $category || in_array($category, $set['aliases'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array{aliases: array<int, string>, attributes: array<int, string>}  $set
     */
    private function resolveCategory(string $key, array $set): ?Category
    {
        return Category::query()
            ->whereIn('slug', $this->categoryCandidates($key, $set))
            ->orderByRaw($this->categoryOrderExpression($this->categoryCandidates($key, $set)))
            ->first();
    }

    /**
     * @param  array{aliases: array<int, string>, attributes: array<int, string>}  $set
     * @return array<int, string>
     */
    private function categoryCandidates(string $key, array $set): array
    {
        $category = $this->normalizedCategoryOption();

        if ($category === null || $category === $key) {
            return $set['aliases'];
        }

        return [$category];
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function categoryOrderExpression(array $slugs): string
    {
        $cases = collect($slugs)
            ->values()
            ->map(fn (string $slug, int $index): string => "WHEN '".str_replace("'", "''", $slug)."' THEN ".$index)
            ->implode(' ');

        return 'CASE slug '.$cases.' ELSE '.count($slugs).' END';
    }

    /**
     * @return array{attribute: ?ProductAttribute, matched_by: ?string}
     */
    private function resolveAttribute(string $code): array
    {
        $attribute = ProductAttribute::query()
            ->where('code', $code)
            ->first();

        if ($attribute) {
            return [
                'attribute' => $attribute,
                'matched_by' => 'code',
            ];
        }

        $attribute = ProductAttribute::query()
            ->where('slug', Str::slug($code))
            ->first();

        return [
            'attribute' => $attribute,
            'matched_by' => $attribute ? 'slug' : null,
        ];
    }

    /**
     * @param  array{sets_considered: int, categories_found: int, categories_skipped: int, assignments_to_create: int, assignments_existing: int, attributes_missing: int, messages: array<int, string>}  $stats
     * @param  array{attribute: ?ProductAttribute, matched_by: ?string}  $resolved
     */
    private function recordSlugCodeMismatch(array &$stats, string $setKey, string $requestedCode, array $resolved): void
    {
        $attribute = $resolved['attribute'];

        if (! $attribute || $resolved['matched_by'] !== 'slug' || $attribute->code === $requestedCode) {
            return;
        }

        $stats['messages'][] = sprintf(
            'Reused attribute by slug/code mismatch for set "%s": requested code "%s" matched existing code "%s" with slug "%s".',
            $setKey,
            $requestedCode,
            $attribute->code,
            $attribute->slug,
        );
    }

    private function normalizedSetOption(string $name): ?string
    {
        $value = $this->option($name);

        return filled($value) ? Str::slug((string) $value, '_') : null;
    }

    private function normalizedCategoryOption(): ?string
    {
        $value = $this->option('category');

        return filled($value) ? Str::slug((string) $value) : null;
    }

    /**
     * @return array{sets_considered: int, categories_found: int, categories_skipped: int, assignments_to_create: int, assignments_existing: int, attributes_missing: int, messages: array<int, string>}
     */
    private function emptyStats(): array
    {
        return [
            'sets_considered' => 0,
            'categories_found' => 0,
            'categories_skipped' => 0,
            'assignments_to_create' => 0,
            'assignments_existing' => 0,
            'attributes_missing' => 0,
            'messages' => [],
        ];
    }

    /**
     * @return array<string, array{aliases: array<int, string>, attributes: array<int, string>}>
     */
    private function categorySets(): array
    {
        return [
            'laptops' => $this->set(
                ['laptopi', 'laptops', 'notebooks', 'laptop', 'noutbutsi', 'noutbuci'],
                ['processor', 'ram', 'storage_capacity', 'storage_type', 'gpu', 'screen_size', 'resolution', 'refresh_rate', 'panel_type', 'operating_system', 'color', 'warranty_months', 'weight'],
            ),
            'monitors' => $this->set(
                ['monitori', 'monitors', 'monitor'],
                ['screen_size', 'resolution', 'refresh_rate', 'panel_type', 'interface', 'connectors', 'color', 'warranty_months', 'weight', 'power_watts'],
            ),
            'phones' => $this->set(
                ['iphone', 'telefoni', 'smartphones', 'smartfoni', 'phone', 'phones'],
                ['processor', 'storage_capacity', 'screen_size', 'resolution', 'refresh_rate', 'color', 'operating_system', 'warranty_months', 'weight'],
            ),
            'tablets' => $this->set(
                ['tablets', 'tableti', 'tablet'],
                ['processor', 'ram', 'storage_capacity', 'screen_size', 'resolution', 'refresh_rate', 'color', 'operating_system', 'warranty_months', 'weight'],
            ),
            'keyboards' => $this->set(
                ['klaviaturi', 'keyboards', 'keyboard'],
                ['interface', 'connectors', 'compatibility', 'color', 'warranty_months', 'weight'],
            ),
            'mice' => $this->set(
                ['mishki', 'mouses', 'mice', 'mouse'],
                ['interface', 'connectors', 'compatibility', 'color', 'warranty_months', 'weight'],
            ),
            'cables' => $this->set(
                ['cables', 'kabeli', 'cable'],
                ['cable_length', 'connectors', 'interface', 'color', 'compatibility'],
            ),
            'printers' => $this->set(
                ['printeri', 'printers', 'printer'],
                ['interface', 'connectors', 'compatibility', 'color', 'warranty_months', 'weight'],
            ),
        ];
    }

    /**
     * @param  array<int, string>  $aliases
     * @param  array<int, string>  $attributes
     * @return array{aliases: array<int, string>, attributes: array<int, string>}
     */
    private function set(array $aliases, array $attributes): array
    {
        return [
            'aliases' => collect($aliases)
                ->map(fn (string $alias): string => Str::slug($alias))
                ->all(),
            'attributes' => $attributes,
        ];
    }
}
