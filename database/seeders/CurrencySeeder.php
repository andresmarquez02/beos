<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->upsert([
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => '1.000000'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.920000'],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'exchange_rate' => '0.790000'],
            ['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'exchange_rate' => '4100.000000'],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$', 'exchange_rate' => '17.000000'],
        ], ['code'], ['name', 'symbol', 'exchange_rate']);
    }
}
