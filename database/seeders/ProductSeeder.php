<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Productos de ejemplo con precios en otras divisas, para probar la API sin crear datos a mano.
     * Valores fijos (no aleatorios) para que las pruebas manuales sean reproducibles.
     *
     * @var list<array{name: string, description: string, price: string, currency: string, tax_cost: string, manufacturing_cost: string, prices: array<string, string>}>
     */
    private const PRODUCTS = [
        [
            'name' => 'Wireless Mouse',
            'description' => 'Ergonomic 2.4 GHz wireless mouse.',
            'price' => '25.99',
            'currency' => 'USD',
            'tax_cost' => '4.94',
            'manufacturing_cost' => '9.50',
            'prices' => ['EUR' => '23.90', 'COP' => '106500.00'],
        ],
        [
            'name' => 'Mechanical Keyboard',
            'description' => 'Tenkeyless keyboard with brown switches.',
            'price' => '89.00',
            'currency' => 'USD',
            'tax_cost' => '16.91',
            'manufacturing_cost' => '41.00',
            'prices' => ['EUR' => '82.00', 'GBP' => '70.50', 'MXN' => '1513.00'],
        ],
        [
            'name' => 'USB-C Hub',
            'description' => '7-in-1 hub with HDMI and card reader.',
            'price' => '45.00',
            'currency' => 'EUR',
            'tax_cost' => '9.45',
            'manufacturing_cost' => '18.20',
            'prices' => ['USD' => '48.90'],
        ],
        [
            'name' => 'Laptop Stand',
            'description' => 'Adjustable aluminium stand.',
            'price' => '120000.00',
            'currency' => 'COP',
            'tax_cost' => '22800.00',
            'manufacturing_cost' => '54000.00',
            'prices' => [],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $currencies = Currency::query()->pluck('id', 'code');

        foreach (self::PRODUCTS as $data) {
            // Idempotente: si el producto ya existe por nombre, no se duplica.
            $product = Product::query()->firstOrCreate(['name' => $data['name']], [
                'description' => $data['description'],
                'price' => $data['price'],
                'currency_id' => $currencies[$data['currency']],
                'tax_cost' => $data['tax_cost'],
                'manufacturing_cost' => $data['manufacturing_cost'],
            ]);

            foreach ($data['prices'] as $code => $price) {
                $product->prices()->firstOrCreate(['currency_id' => $currencies[$code]], ['price' => $price]);
            }
        }
    }
}
