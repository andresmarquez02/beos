<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->words(3, true),
            'symbol' => fake()->randomElement(['$', '€', '£', '¥']),
            'exchange_rate' => number_format(fake()->randomFloat(6, 0.1, 5000), 6, '.', ''),
        ];
    }
}
