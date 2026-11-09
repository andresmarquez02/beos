<?php

declare(strict_types=1);

namespace Tests\Unit\Dtos;

use App\DTOs\ProductData;
use PHPUnit\Framework\TestCase;

class ProductDataTest extends TestCase
{
    public function test_maps_validated_data_keeping_amounts_as_strings(): void
    {
        $data = ProductData::fromValidated([
            'name' => 'Laptop',
            'description' => 'Desc',
            'price' => '1299.90',
            'currency_id' => '3',
            'tax_cost' => 249,
            'manufacturing_cost' => 800.5,
        ]);

        $this->assertSame('Laptop', $data->name);
        $this->assertSame('Desc', $data->description);
        $this->assertSame('1299.90', $data->price);
        $this->assertSame(3, $data->currencyId);
        $this->assertSame('249.00', $data->taxCost);
        $this->assertSame('800.50', $data->manufacturingCost);
    }

    public function test_description_defaults_to_null(): void
    {
        $data = ProductData::fromValidated([
            'name' => 'Laptop', 'description' => null, 'price' => '1', 'currency_id' => 1, 'tax_cost' => '0', 'manufacturing_cost' => '0',
        ]);

        $this->assertNull($data->description);
    }

    public function test_ignores_unexpected_keys(): void
    {
        $data = ProductData::fromValidated([
            'id' => 99, 'deleted_at' => 'x', 'name' => 'A', 'price' => '1', 'currency_id' => 1, 'tax_cost' => '0', 'manufacturing_cost' => '0',
        ]);

        $this->assertFalse(property_exists($data, 'id'));
        $this->assertFalse(property_exists($data, 'deletedAt'));
    }
}
