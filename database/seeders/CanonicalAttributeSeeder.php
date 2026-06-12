<?php

namespace Database\Seeders;

use App\Models\AttributeAlias;
use App\Models\AttributeValueAlias;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Services\Attributes\AttributeTextNormalizer;
use App\Services\Attributes\UnitConversionService;
use Illuminate\Database\Seeder;

class CanonicalAttributeSeeder extends Seeder
{
    public function run(): void
    {
        $text = app(AttributeTextNormalizer::class);
        $units = app(UnitConversionService::class);

        $attributes = [
            ['code' => 'cpu', 'name' => 'CPU', 'group_name' => 'Performance', 'type' => 'text', 'aliases' => ['Processor', 'Процесор']],
            ['code' => 'cpu_socket', 'name' => 'CPU Socket', 'group_name' => 'CPU Details', 'type' => 'select', 'aliases' => ['Socket', 'CPU Socket', 'Сокет']],
            ['code' => 'ram', 'name' => 'RAM', 'group_name' => 'Performance', 'type' => 'capacity', 'unit' => 'gb', 'aliases' => ['Memory', 'Оперативна памет', 'Памет']],
            ['code' => 'memory_type', 'name' => 'RAM Type', 'group_name' => 'Performance', 'type' => 'select', 'aliases' => ['RAM Type', 'Memory Type', 'DDR']],
            ['code' => 'storage', 'name' => 'Storage', 'group_name' => 'Storage', 'type' => 'capacity', 'unit' => 'gb', 'aliases' => ['Disk', 'Drive', 'Накопител']],
            ['code' => 'storage_type', 'name' => 'Storage Type', 'group_name' => 'Storage', 'type' => 'select', 'aliases' => ['Disk Type', 'Drive Type']],
            ['code' => 'gpu', 'name' => 'GPU', 'group_name' => 'Performance', 'type' => 'text', 'aliases' => ['Graphics', 'Graphics Card', 'Видео карта', 'Видеокарта']],
            ['code' => 'display_size', 'name' => 'Display Size', 'group_name' => 'Display', 'type' => 'dimension', 'unit' => 'inch', 'aliases' => ['Display', 'Screen Size', 'Екран']],
            ['code' => 'resolution', 'name' => 'Display Resolution', 'group_name' => 'Display', 'type' => 'resolution', 'aliases' => ['Resolution', 'Резолюция']],
            ['code' => 'refresh_rate', 'name' => 'Refresh Rate', 'group_name' => 'Display', 'type' => 'frequency', 'unit' => 'hz', 'aliases' => ['Refresh rate', 'Честота']],
            ['code' => 'operating_system', 'name' => 'Operating System', 'group_name' => 'Software', 'type' => 'select', 'aliases' => ['OS', 'Операционна система']],
            ['code' => 'battery_capacity', 'name' => 'Battery Capacity', 'group_name' => 'Battery', 'type' => 'capacity', 'aliases' => ['Battery', 'Батерия']],
            ['code' => 'weight', 'name' => 'Weight', 'group_name' => 'Physical', 'type' => 'weight', 'unit' => 'kg', 'aliases' => ['Тегло']],
            ['code' => 'motherboard_socket', 'name' => 'Motherboard Socket', 'group_name' => 'Motherboard', 'type' => 'select', 'aliases' => ['Board Socket', 'Mainboard Socket']],
            ['code' => 'chipset', 'name' => 'Chipset', 'group_name' => 'Motherboard', 'type' => 'select', 'aliases' => ['Чипсет']],
            ['code' => 'psu_wattage', 'name' => 'PSU Wattage', 'group_name' => 'Power', 'type' => 'power', 'unit' => 'w', 'aliases' => ['Wattage', 'Power', 'PSU Power', 'Мощност']],
            ['code' => 'form_factor', 'name' => 'Case Form Factor', 'group_name' => 'Case', 'type' => 'select', 'aliases' => ['Form Factor', 'Case Size']],
            ['code' => 'cooler_socket_support', 'name' => 'Cooler Socket Support', 'group_name' => 'Cooling', 'type' => 'multiselect', 'aliases' => ['Cooler Socket', 'Socket Support']],
            ['code' => 'print_technology', 'name' => 'Print Technology', 'group_name' => 'Printer', 'type' => 'select', 'aliases' => ['Technology', 'Технология на печат']],
            ['code' => 'color_printing', 'name' => 'Color Printing', 'group_name' => 'Printer', 'type' => 'boolean', 'aliases' => ['Color', 'Цветен печат']],
            ['code' => 'duplex', 'name' => 'Duplex', 'group_name' => 'Printer', 'type' => 'boolean', 'aliases' => ['Double sided', 'Двустранен печат']],
            ['code' => 'connectivity', 'name' => 'Connectivity', 'group_name' => 'Connectivity', 'type' => 'multiselect', 'aliases' => ['Ports', 'Интерфейси', 'Свързаност']],
            ['code' => 'paper_size', 'name' => 'Paper Size', 'group_name' => 'Printer', 'type' => 'select', 'aliases' => ['Media Size', 'Размер хартия']],
            ['code' => 'panel_type', 'name' => 'Panel Type', 'group_name' => 'Display', 'type' => 'select', 'aliases' => ['Panel', 'Тип матрица']],
            ['code' => 'response_time', 'name' => 'Response Time', 'group_name' => 'Display', 'type' => 'number', 'unit' => 'ms', 'aliases' => ['Response', 'Време за реакция']],
        ];

        foreach ($attributes as $index => $data) {
            $aliases = $data['aliases'];
            unset($data['aliases']);

            $attribute = CanonicalAttribute::query()->updateOrCreate(
                ['code' => $data['code']],
                $data + [
                    'is_filterable' => true,
                    'is_comparable' => true,
                    'is_required' => false,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                ],
            );

            foreach (array_merge([$attribute->name, $attribute->code], $aliases) as $alias) {
                AttributeAlias::query()->updateOrCreate(
                    [
                        'canonical_attribute_id' => $attribute->id,
                        'normalized_alias' => $text->normalize($alias),
                        'supplier_id' => null,
                    ],
                    [
                        'alias' => $alias,
                        'confidence' => 100,
                        'is_active' => true,
                    ],
                );
            }
        }

        $this->seedValues($text, $units);
    }

    private function seedValues(AttributeTextNormalizer $text, UnitConversionService $units): void
    {
        $values = [
            'ram' => ['16 GB' => ['16GB', '16384 MB', '16 гб'], '32 GB' => ['32GB', '32768 MB'], '8 GB' => ['8GB', '8192 MB']],
            'storage' => ['1 TB' => ['1024 GB', '1TB'], '512 GB' => ['512GB'], '2 TB' => ['2048 GB', '2TB']],
            'memory_type' => ['DDR5' => ['DDR 5', 'DDR5 SDRAM'], 'DDR4' => ['DDR 4', 'DDR4 SDRAM']],
            'psu_wattage' => ['750 W' => ['750W', '0.75 kW'], '650 W' => ['650W', '0.65 kW']],
            'display_size' => ['15.6 "' => ['15.6 inch', '15.6 инча', '15.6"'], '27 "' => ['27 inch', '27 инча', '27"']],
            'cpu_socket' => ['AM5' => ['AMD AM5'], 'LGA1700' => ['Intel LGA1700']],
            'motherboard_socket' => ['AM5' => ['AMD AM5'], 'LGA1700' => ['Intel LGA1700']],
        ];

        foreach ($values as $attributeCode => $attributeValues) {
            $attribute = CanonicalAttribute::query()->where('code', $attributeCode)->first();
            if (! $attribute) {
                continue;
            }

            foreach ($attributeValues as $display => $aliases) {
                $converted = $units->normalize($display, $attribute->unit);
                $value = CanonicalAttributeValue::query()->updateOrCreate(
                    [
                        'canonical_attribute_id' => $attribute->id,
                        'normalized_value' => $converted['normalized_value'],
                    ],
                    [
                        'display_value' => $display,
                        'numeric_value' => $converted['numeric_value'],
                        'unit' => $converted['unit'],
                        'is_active' => true,
                    ],
                );

                foreach (array_merge([$display], $aliases) as $alias) {
                    AttributeValueAlias::query()->updateOrCreate(
                        [
                            'canonical_attribute_value_id' => $value->id,
                            'normalized_alias' => $text->normalize($alias),
                            'supplier_id' => null,
                        ],
                        [
                            'alias' => $alias,
                            'confidence' => 100,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }
}
