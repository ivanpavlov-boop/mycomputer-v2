<?php

namespace App\Console\Commands;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedStarterProductAttributes extends Command
{
    protected $signature = 'product-attributes:seed-starter
        {--apply : Persist missing starter attributes and options}
        {--dry-run : Preview changes without writing anything}';

    protected $description = 'Preview or apply the controlled starter product attribute library.';

    private const GROUP_SLUG = 'starter-product-characteristics';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $stats = $apply ? $this->applyStarterStructure() : $this->previewStarterStructure();

        $this->info($apply
            ? 'Starter product attributes applied.'
            : 'Dry-run only. No records were changed.');

        $this->line('Attributes to create: '.$stats['attributes_to_create']);
        $this->line('Attributes already present: '.$stats['attributes_existing']);
        $this->line('Options to create: '.$stats['options_to_create']);
        $this->line('Options already present: '.$stats['options_existing']);
        $this->line('Category assignments: deferred');
        $this->line('Products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('product_attribute_values created: 0');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function previewStarterStructure(): array
    {
        $stats = $this->emptyStats();

        foreach ($this->starterAttributes() as $definition) {
            $attribute = ProductAttribute::withTrashed()
                ->where('code', $definition['code'])
                ->first();

            if (! $attribute) {
                $stats['attributes_to_create']++;
                $stats['options_to_create'] += count($definition['options']);

                continue;
            }

            $stats['attributes_existing']++;

            foreach ($definition['options'] as $option) {
                $exists = AttributeValue::withTrashed()
                    ->where('product_attribute_id', $attribute->id)
                    ->where('slug', $option['slug'])
                    ->exists();

                $exists ? $stats['options_existing']++ : $stats['options_to_create']++;
            }
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function applyStarterStructure(): array
    {
        $stats = $this->emptyStats();

        DB::transaction(function () use (&$stats): void {
            $group = AttributeGroup::query()->firstOrCreate(
                ['slug' => self::GROUP_SLUG],
                [
                    'name' => 'Основни характеристики',
                    'name_translations' => ['en' => 'Core specifications'],
                    'description' => 'Контролирана стартова структура за продуктови характеристики.',
                    'description_translations' => ['en' => 'Controlled starter structure for product attributes.'],
                    'sort_order' => 10,
                    'is_active' => true,
                ],
            );

            foreach ($this->starterAttributes() as $definition) {
                $attribute = ProductAttribute::withTrashed()
                    ->where('code', $definition['code'])
                    ->first();

                if (! $attribute) {
                    $attribute = ProductAttribute::query()->create([
                        'attribute_group_id' => $group->id,
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

                    $stats['attributes_to_create']++;
                } else {
                    $stats['attributes_existing']++;
                }

                if ($attribute->trashed()) {
                    continue;
                }

                foreach ($definition['options'] as $option) {
                    $exists = AttributeValue::withTrashed()
                        ->where('product_attribute_id', $attribute->id)
                        ->where('slug', $option['slug'])
                        ->exists();

                    if ($exists) {
                        $stats['options_existing']++;

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

                    $stats['options_to_create']++;
                }
            }
        });

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'attributes_to_create' => 0,
            'attributes_existing' => 0,
            'options_to_create' => 0,
            'options_existing' => 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function starterAttributes(): array
    {
        return [
            $this->attribute('ram', 'RAM', 'RAM', ProductAttribute::TYPE_SELECT, 'GB', true, true, true, 10, [
                ['4 GB', '4 GB', '4-gb'],
                ['8 GB', '8 GB', '8-gb'],
                ['16 GB', '16 GB', '16-gb'],
                ['32 GB', '32 GB', '32-gb'],
                ['64 GB', '64 GB', '64-gb'],
                ['128 GB', '128 GB', '128-gb'],
            ]),
            $this->attribute('storage_capacity', 'Капацитет на паметта', 'Storage capacity', ProductAttribute::TYPE_SELECT, 'GB', true, true, true, 20, [
                ['128 GB', '128 GB', '128-gb'],
                ['256 GB', '256 GB', '256-gb'],
                ['512 GB', '512 GB', '512-gb'],
                ['1 TB', '1 TB', '1-tb'],
                ['2 TB', '2 TB', '2-tb'],
                ['4 TB', '4 TB', '4-tb'],
            ]),
            $this->attribute('storage_type', 'Тип памет', 'Storage type', ProductAttribute::TYPE_SELECT, null, true, true, true, 30, [
                ['SSD', 'SSD', 'ssd'],
                ['HDD', 'HDD', 'hdd'],
                ['NVMe', 'NVMe', 'nvme'],
            ]),
            $this->attribute('processor', 'Процесор', 'Processor', ProductAttribute::TYPE_TEXT, null, true, true, true, 40),
            $this->attribute('gpu', 'Видео карта', 'Graphics card', ProductAttribute::TYPE_TEXT, null, true, true, true, 50),
            $this->attribute('screen_size', 'Размер на екрана', 'Screen size', ProductAttribute::TYPE_SELECT, 'inch', true, true, true, 60, [
                ['13"', '13"', '13-inch'],
                ['14"', '14"', '14-inch'],
                ['15.6"', '15.6"', '15-6-inch'],
                ['16"', '16"', '16-inch'],
                ['17.3"', '17.3"', '17-3-inch'],
                ['24"', '24"', '24-inch'],
                ['27"', '27"', '27-inch'],
                ['32"', '32"', '32-inch'],
            ]),
            $this->attribute('resolution', 'Резолюция', 'Resolution', ProductAttribute::TYPE_SELECT, null, false, true, true, 70, [
                ['Full HD', 'Full HD', 'full-hd'],
                ['QHD', 'QHD', 'qhd'],
                ['4K UHD', '4K UHD', '4k-uhd'],
            ]),
            $this->attribute('refresh_rate', 'Честота на опресняване', 'Refresh rate', ProductAttribute::TYPE_SELECT, 'Hz', true, true, true, 80, [
                ['60 Hz', '60 Hz', '60-hz'],
                ['75 Hz', '75 Hz', '75-hz'],
                ['100 Hz', '100 Hz', '100-hz'],
                ['120 Hz', '120 Hz', '120-hz'],
                ['144 Hz', '144 Hz', '144-hz'],
                ['165 Hz', '165 Hz', '165-hz'],
                ['240 Hz', '240 Hz', '240-hz'],
            ]),
            $this->attribute('panel_type', 'Тип матрица', 'Panel type', ProductAttribute::TYPE_SELECT, null, true, true, true, 90, [
                ['IPS', 'IPS', 'ips'],
                ['VA', 'VA', 'va'],
                ['OLED', 'OLED', 'oled'],
                ['Mini LED', 'Mini LED', 'mini-led'],
                ['TN', 'TN', 'tn'],
            ]),
            $this->attribute('color', 'Цвят', 'Color', ProductAttribute::TYPE_SELECT, null, true, true, false, 100, [
                ['Черен', 'Black', 'black'],
                ['Бял', 'White', 'white'],
                ['Сребрист', 'Silver', 'silver'],
                ['Сив', 'Gray', 'gray'],
                ['Син', 'Blue', 'blue'],
                ['Червен', 'Red', 'red'],
            ]),
            $this->attribute('operating_system', 'Операционна система', 'Operating system', ProductAttribute::TYPE_SELECT, null, true, true, true, 110, [
                ['Windows 11 Home', 'Windows 11 Home', 'windows-11-home'],
                ['Windows 11 Pro', 'Windows 11 Pro', 'windows-11-pro'],
                ['macOS', 'macOS', 'macos'],
                ['Linux', 'Linux', 'linux'],
                ['Без операционна система', 'No operating system', 'no-operating-system'],
            ]),
            $this->attribute('warranty_months', 'Гаранция', 'Warranty', ProductAttribute::TYPE_NUMBER, 'месеца', false, true, true, 120),
            $this->attribute('interface', 'Интерфейс', 'Interface', ProductAttribute::TYPE_MULTISELECT, null, true, true, false, 130),
            $this->attribute('connectors', 'Конектори', 'Connectors', ProductAttribute::TYPE_MULTISELECT, null, false, true, false, 140),
            $this->attribute('cable_length', 'Дължина на кабел', 'Cable length', ProductAttribute::TYPE_DECIMAL, 'm', true, true, false, 150),
            $this->attribute('compatibility', 'Съвместимост', 'Compatibility', ProductAttribute::TYPE_TEXT, null, false, true, false, 160),
            $this->attribute('power_watts', 'Мощност', 'Power', ProductAttribute::TYPE_NUMBER, 'W', true, true, false, 170),
            $this->attribute('weight', 'Тегло', 'Weight', ProductAttribute::TYPE_DECIMAL, 'kg', false, true, true, 180),
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
