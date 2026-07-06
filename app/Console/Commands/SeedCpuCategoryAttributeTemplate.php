<?php

namespace App\Console\Commands;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\ProductAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedCpuCategoryAttributeTemplate extends Command
{
    protected $signature = 'product-attributes:seed-cpu-template
        {--apply : Persist missing CPU attributes, safe options, and category assignments}
        {--dry-run : Preview changes without writing anything}';

    protected $description = 'Preview or apply the controlled CPU category product attribute template.';

    private const GROUP_SLUG = 'cpu-specifications';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $stats = $apply
            ? DB::transaction(fn (): array => $this->processTemplate(true))
            : $this->processTemplate(false);

        $this->info($apply
            ? 'CPU category attribute template applied.'
            : 'Dry-run only. No records were changed.');

        $this->line(($apply ? 'Product attributes created: ' : 'Product attributes to create: ').$stats['attributes_to_create']);
        $this->line('Product attributes already present: '.$stats['attributes_existing']);
        $this->line('Attribute slug/code mismatches reused: '.$stats['attribute_slug_code_mismatches']);
        $this->line(($apply ? 'Attribute values created: ' : 'Attribute values to create: ').$stats['options_to_create']);
        $this->line('Attribute values already present: '.$stats['options_existing']);
        $this->line('CPU categories found: '.$stats['categories_found']);
        $this->line('CPU categories skipped: '.$stats['categories_skipped']);
        $this->line(($apply ? 'Category assignments created: ' : 'Category assignments to create: ').$stats['assignments_to_create']);
        $this->line('Category assignments already present: '.$stats['assignments_existing']);
        $this->line('Categories created: 0');
        $this->line('Categories changed: 0');
        $this->line('Products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('product_attribute_values created: 0');
        $this->line('product_attribute_values changed: 0');
        $this->line('Product category assignments changed: 0');

        foreach ($stats['messages'] as $message) {
            $this->warn($message);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     attributes_to_create: int,
     *     attributes_existing: int,
     *     attribute_slug_code_mismatches: int,
     *     options_to_create: int,
     *     options_existing: int,
     *     categories_found: int,
     *     categories_skipped: int,
     *     assignments_to_create: int,
     *     assignments_existing: int,
     *     messages: array<int, string>
     * }
     */
    private function processTemplate(bool $apply): array
    {
        $stats = $this->emptyStats();
        $categories = $this->resolveCpuCategories();

        if ($categories->isEmpty()) {
            $stats['categories_skipped'] = 1;
            $stats['messages'][] = 'Skipped CPU category template: none of these category slugs exist: '.implode(', ', $this->cpuCategoryAliases()).'.';
        } else {
            $stats['categories_found'] = $categories->count();
        }

        $group = $apply ? $this->cpuAttributeGroup() : null;
        $resolvedAttributes = [];

        foreach ($this->cpuAttributes() as $definition) {
            $resolved = $this->resolveAttribute($definition);
            $attribute = $resolved['attribute'];

            if (! $attribute) {
                $stats['attributes_to_create']++;

                if ($apply) {
                    $attribute = ProductAttribute::query()->create([
                        'attribute_group_id' => $group?->id,
                        'code' => $definition['code'],
                        'name' => $definition['name_bg'],
                        'name_bg' => $definition['name_bg'],
                        'name_en' => $definition['name_en'],
                        'slug' => Str::slug($definition['code']),
                        'type' => $definition['type'],
                        'unit' => $definition['unit'],
                        'sort_order' => $definition['sort_order'],
                        'is_filterable' => $definition['is_filterable'],
                        'is_visible_on_product' => $definition['is_visible_on_product'],
                        'is_comparable' => $definition['is_comparable'],
                        'is_required' => false,
                        'is_required_by_default' => false,
                        'is_active' => true,
                    ]);
                } else {
                    $stats['options_to_create'] += count($definition['options']);
                }
            } else {
                $stats['attributes_existing']++;
                $this->recordSlugCodeMismatch($stats, $resolved, $definition);

                if ($apply) {
                    $this->fillMissingTechnicalFields($attribute, $definition);
                    $attribute->refresh();
                }
            }

            if ($attribute && $attribute->trashed()) {
                $stats['messages'][] = sprintf(
                    'Skipped soft-deleted attribute "%s"; restore it manually before using it in the CPU template.',
                    $definition['code'],
                );
                $resolvedAttributes[$definition['code']] = null;

                continue;
            }

            if ($attribute) {
                $this->processOptions($stats, $attribute, $definition, $apply);
            }

            $resolvedAttributes[$definition['code']] = $attribute;
        }

        foreach ($categories as $category) {
            foreach ($this->cpuAttributes() as $index => $definition) {
                $attribute = $resolvedAttributes[$definition['code']] ?? null;
                $wouldCreateAttribute = $attribute === null && ! ProductAttribute::withTrashed()
                    ->where('code', $definition['code'])
                    ->orWhere('slug', Str::slug($definition['code']))
                    ->exists();

                if (! $attribute && ! $wouldCreateAttribute) {
                    continue;
                }

                if (! $attribute && ! $apply) {
                    $stats['assignments_to_create']++;

                    continue;
                }

                if (! $attribute) {
                    continue;
                }

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

    private function cpuAttributeGroup(): AttributeGroup
    {
        return AttributeGroup::query()->firstOrCreate(
            ['slug' => self::GROUP_SLUG],
            [
                'name' => 'CPU характеристики',
                'name_translations' => ['en' => 'CPU specifications'],
                'description' => 'Контролирана структура за процесорни характеристики.',
                'description_translations' => ['en' => 'Controlled structure for CPU product specifications.'],
                'sort_order' => 20,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{attribute: ?ProductAttribute, matched_by: ?string}
     */
    private function resolveAttribute(array $definition): array
    {
        $attribute = ProductAttribute::withTrashed()
            ->where('code', $definition['code'])
            ->first();

        if ($attribute) {
            return [
                'attribute' => $attribute,
                'matched_by' => 'code',
            ];
        }

        $attribute = ProductAttribute::withTrashed()
            ->where('slug', Str::slug($definition['code']))
            ->first();

        return [
            'attribute' => $attribute,
            'matched_by' => $attribute ? 'slug' : null,
        ];
    }

    /**
     * @param  array{
     *     attributes_to_create: int,
     *     attributes_existing: int,
     *     attribute_slug_code_mismatches: int,
     *     options_to_create: int,
     *     options_existing: int,
     *     categories_found: int,
     *     categories_skipped: int,
     *     assignments_to_create: int,
     *     assignments_existing: int,
     *     messages: array<int, string>
     * }  $stats
     * @param  array{attribute: ?ProductAttribute, matched_by: ?string}  $resolved
     * @param  array<string, mixed>  $definition
     */
    private function recordSlugCodeMismatch(array &$stats, array $resolved, array $definition): void
    {
        $attribute = $resolved['attribute'];

        if (! $attribute || $resolved['matched_by'] !== 'slug' || $attribute->code === $definition['code']) {
            return;
        }

        $stats['attribute_slug_code_mismatches']++;
        $stats['messages'][] = sprintf(
            'Reused existing attribute by slug/code mismatch: CPU code "%s" maps to existing code "%s" with slug "%s". Existing labels were preserved.',
            $definition['code'],
            $attribute->code,
            $attribute->slug,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function fillMissingTechnicalFields(ProductAttribute $attribute, array $definition): void
    {
        if ($attribute->trashed()) {
            return;
        }

        $updates = [];

        foreach (['type', 'unit'] as $field) {
            if (blank($attribute->{$field}) && filled($definition[$field])) {
                $updates[$field] = $definition[$field];
            }
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();

            ProductAttribute::query()
                ->whereKey($attribute->id)
                ->update($updates);
        }
    }

    /**
     * @param  array{
     *     attributes_to_create: int,
     *     attributes_existing: int,
     *     attribute_slug_code_mismatches: int,
     *     options_to_create: int,
     *     options_existing: int,
     *     categories_found: int,
     *     categories_skipped: int,
     *     assignments_to_create: int,
     *     assignments_existing: int,
     *     messages: array<int, string>
     * }  $stats
     * @param  array<string, mixed>  $definition
     */
    private function processOptions(array &$stats, ProductAttribute $attribute, array $definition, bool $apply): void
    {
        if ($definition['options'] === []) {
            return;
        }

        if (! in_array($attribute->type, [ProductAttribute::TYPE_SELECT, ProductAttribute::TYPE_MULTISELECT], true)) {
            $stats['messages'][] = sprintf(
                'Skipped options for "%s" because existing attribute type is "%s". Existing type was preserved.',
                $definition['code'],
                $attribute->type,
            );

            return;
        }

        foreach ($definition['options'] as $option) {
            $exists = $this->findExistingOption($attribute, $option) !== null;

            if ($exists) {
                $stats['options_existing']++;

                continue;
            }

            $stats['options_to_create']++;

            if (! $apply) {
                continue;
            }

            AttributeValue::query()->create([
                'product_attribute_id' => $attribute->id,
                'value' => $option['value'],
                'value_translations' => ['en' => $option['en']],
                'slug' => $option['slug'],
                'sort_order' => $option['sort_order'],
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array{value: string, en: string, slug: string, sort_order: int}  $option
     */
    private function findExistingOption(ProductAttribute $attribute, array $option): ?AttributeValue
    {
        return AttributeValue::withTrashed()
            ->where('product_attribute_id', $attribute->id)
            ->where(function ($query) use ($option): void {
                $query
                    ->where('slug', $option['slug'])
                    ->orWhere('value', $option['value']);
            })
            ->first();
    }

    /**
     * @return Collection<int, Category>
     */
    private function resolveCpuCategories(): Collection
    {
        $order = array_flip($this->cpuCategoryAliases());

        return Category::query()
            ->whereIn('slug', $this->cpuCategoryAliases())
            ->get()
            ->sortBy(fn (Category $category): int => $order[$category->slug] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function cpuCategoryAliases(): array
    {
        return collect([
            'procesori',
            'processors',
            'processor',
            'cpu',
            'cpus',
            'protsesori',
            'central-processors',
            'desktop-processors',
        ])
            ->map(fn (string $slug): string => Str::slug($slug))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     attributes_to_create: int,
     *     attributes_existing: int,
     *     attribute_slug_code_mismatches: int,
     *     options_to_create: int,
     *     options_existing: int,
     *     categories_found: int,
     *     categories_skipped: int,
     *     assignments_to_create: int,
     *     assignments_existing: int,
     *     messages: array<int, string>
     * }
     */
    private function emptyStats(): array
    {
        return [
            'attributes_to_create' => 0,
            'attributes_existing' => 0,
            'attribute_slug_code_mismatches' => 0,
            'options_to_create' => 0,
            'options_existing' => 0,
            'categories_found' => 0,
            'categories_skipped' => 0,
            'assignments_to_create' => 0,
            'assignments_existing' => 0,
            'messages' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cpuAttributes(): array
    {
        return [
            $this->attribute('processor', 'Процесор', 'Processor', ProductAttribute::TYPE_TEXT, null, true, true, true, 10),
            $this->attribute('cpu_socket', 'Сокет', 'CPU socket', ProductAttribute::TYPE_SELECT, null, true, true, true, 20, [
                ['AM4', 'AM4', 'am4'],
                ['AM5', 'AM5', 'am5'],
                ['LGA1700', 'LGA1700', 'lga1700'],
                ['LGA1851', 'LGA1851', 'lga1851'],
            ]),
            $this->attribute('cpu_cores', 'Ядра', 'CPU cores', ProductAttribute::TYPE_NUMBER, null, true, true, true, 30),
            $this->attribute('cpu_threads', 'Нишки', 'CPU threads', ProductAttribute::TYPE_NUMBER, null, false, true, true, 40),
            $this->attribute('cpu_base_clock', 'Базова честота', 'Base clock', ProductAttribute::TYPE_DECIMAL, 'GHz', false, true, true, 50),
            $this->attribute('cpu_boost_clock', 'Boost честота', 'Boost clock', ProductAttribute::TYPE_DECIMAL, 'GHz', false, true, true, 60),
            $this->attribute('cpu_tdp', 'TDP', 'TDP', ProductAttribute::TYPE_NUMBER, 'W', true, true, true, 70),
            $this->attribute('cpu_cache', 'Кеш памет', 'Cache', ProductAttribute::TYPE_TEXT, 'MB', false, true, true, 80),
            $this->attribute('cpu_architecture', 'Архитектура', 'Architecture', ProductAttribute::TYPE_TEXT, null, true, true, true, 90),
            $this->attribute('cpu_integrated_graphics', 'Интегрирана графика', 'Integrated graphics', ProductAttribute::TYPE_BOOLEAN, null, true, true, true, 100),
            $this->attribute('cpu_memory_support', 'Поддържана памет', 'Memory support', ProductAttribute::TYPE_MULTISELECT, null, false, true, true, 110, [
                ['DDR4', 'DDR4', 'ddr4'],
                ['DDR5', 'DDR5', 'ddr5'],
            ]),
            $this->attribute('warranty_months', 'Гаранция', 'Warranty', ProductAttribute::TYPE_NUMBER, 'месеца', false, true, true, 120),
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $options
     * @return array<string, mixed>
     */
    private function attribute(
        string $code,
        string $nameBg,
        string $nameEn,
        string $type,
        ?string $unit,
        bool $filterable,
        bool $visible,
        bool $comparable,
        int $sortOrder,
        array $options = [],
    ): array {
        return [
            'code' => $code,
            'name_bg' => $nameBg,
            'name_en' => $nameEn,
            'type' => $type,
            'unit' => $unit,
            'is_filterable' => $filterable,
            'is_visible_on_product' => $visible,
            'is_comparable' => $comparable,
            'sort_order' => $sortOrder,
            'options' => collect($options)
                ->values()
                ->map(fn (array $option, int $index): array => [
                    'value' => $option[0],
                    'en' => $option[1],
                    'slug' => $option[2],
                    'sort_order' => ($index + 1) * 10,
                ])
                ->all(),
        ];
    }
}
