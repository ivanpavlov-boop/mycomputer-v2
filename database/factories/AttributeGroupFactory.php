<?php

namespace Database\Factories;

use App\Models\AttributeGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttributeGroup>
 */
class AttributeGroupFactory extends Factory
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
            'sort_order' => $this->faker->numberBetween(1, 100),
            'is_active' => true,
        ];
    }
}
