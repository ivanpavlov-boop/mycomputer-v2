<?php

namespace Database\Seeders;

use App\Models\AttributeGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AttributeGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $groups = [
            'Performance' => [
                'Processor' => ['Intel Core Ultra 5', 'AMD Ryzen 7 9700X'],
                'RAM' => ['8 GB', '16 GB', '32 GB'],
                'GPU' => ['Integrated', 'NVIDIA GeForce RTX 4060'],
            ],
            'Storage' => [
                'Storage' => ['512 GB SSD', '1 TB SSD', '2 TB SSD'],
            ],
            'Display' => [
                'Display' => ['15.6 inch', '16 inch', '27 inch'],
                'Resolution' => ['1920x1080', '2560x1440', '3840x2160'],
                'Refresh rate' => ['60 Hz', '144 Hz', '165 Hz'],
            ],
            'CPU Details' => [
                'Cores' => ['6', '8', '12', '16'],
                'Socket' => ['AM5', 'LGA1851'],
                'Base clock' => ['3.8 GHz', '4.2 GHz'],
            ],
        ];

        $groupSort = 1;

        foreach ($groups as $groupName => $attributes) {
            $group = AttributeGroup::query()->updateOrCreate(
                ['slug' => Str::slug($groupName)],
                [
                    'name' => $groupName,
                    'description' => "{$groupName} product specifications.",
                    'sort_order' => $groupSort++,
                    'is_active' => true,
                ],
            );

            $attributeSort = 1;

            foreach ($attributes as $attributeName => $values) {
                $attribute = $group->attributes()->updateOrCreate(
                    ['slug' => Str::slug($attributeName)],
                    [
                        'name' => $attributeName,
                        'type' => 'select',
                        'unit' => null,
                        'sort_order' => $attributeSort++,
                        'is_filterable' => true,
                        'is_required' => false,
                        'is_active' => true,
                    ],
                );

                foreach ($values as $valueSort => $value) {
                    $attribute->values()->updateOrCreate(
                        ['slug' => Str::slug($value)],
                        [
                            'value' => $value,
                            'sort_order' => $valueSort + 1,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }
}
