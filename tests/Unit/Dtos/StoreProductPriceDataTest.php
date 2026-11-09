<?php

declare(strict_types=1);

namespace Tests\Unit\Dtos;

use App\DTOs\StoreProductPriceData;
use PHPUnit\Framework\TestCase;

class StoreProductPriceDataTest extends TestCase
{
    public function test_store_data_maps_currency_and_price_as_string(): void
    {
        $data = StoreProductPriceData::fromValidated(['currency_id' => '2', 'price' => 1199, 'product_id' => 5]);

        $this->assertSame(2, $data->currencyId);
        $this->assertSame('1199.00', $data->price);
        $this->assertFalse(property_exists($data, 'productId'));
    }
}
