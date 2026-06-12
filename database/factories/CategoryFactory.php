<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'icon' => 'heroicon-o-computer-desktop',
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 100),
            'meta_title' => Str::title($name),
            'meta_description' => $this->faker->sentence(),
        ];
    }
}
