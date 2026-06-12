<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
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
            'company_name' => $name,
            'slug' => Str::slug($name),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'website' => $this->faker->url(),
            'priority' => $this->faker->numberBetween(1, 100),
            'sync_strategy' => 'lowest_price',
            'status' => 'active',
        ];
    }
}
