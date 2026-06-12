<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Laptops' => ['Gaming Laptops', 'Business Laptops'],
            'Components' => ['Processors', 'Motherboards', 'Memory', 'Storage', 'Video Cards'],
            'Monitors' => ['Gaming Monitors', 'Office Monitors'],
            'Printers' => ['Laser Printers', 'Inkjet Printers'],
            'Accessories' => ['Keyboards', 'Mice', 'Cables'],
        ];

        $sort = 1;

        foreach ($categories as $name => $children) {
            $category = Category::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => "{$name} catalog section.",
                    'is_active' => true,
                    'sort_order' => $sort++,
                ],
            );

            foreach ($children as $childName) {
                Category::query()->updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    [
                        'parent_id' => $category->id,
                        'name' => $childName,
                        'description' => "{$childName} products.",
                        'is_active' => true,
                        'sort_order' => $sort++,
                    ],
                );
            }
        }
    }
}
