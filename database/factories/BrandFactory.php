<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'website' => $this->faker->url(),
            'description' => $this->faker->sentence(),
            'meta_title' => $name,
            'meta_description' => $this->faker->sentence(),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 100),
        ];
    }
}
