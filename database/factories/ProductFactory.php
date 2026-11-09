<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price' => number_format(fake()->randomFloat(2, 1, 5000), 2, '.', ''),
            'currency_id' => Currency::factory(),
            'tax_cost' => number_format(fake()->randomFloat(2, 0, 500), 2, '.', ''),
            'manufacturing_cost' => number_format(fake()->randomFloat(2, 0, 2000), 2, '.', ''),
        ];
    }
}
