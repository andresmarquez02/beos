<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Http\Resources\V1\ProductPriceResource;
use App\Models\Currency;
use App\Models\ProductPrice;
use Tests\TestCase;

class ProductPriceResourceTest extends TestCase
{
    public function test_exposes_exactly_the_whitelisted_fields(): void
    {
        $price = ProductPrice::factory()->make(['id' => 7, 'product_id' => 21, 'currency_id' => 2, 'price' => '1199']);
        $price->setRelation('currency', Currency::factory()->make(['id' => 2]));

        $data = (new ProductPriceResource($price))->resolve();

        $this->assertSame(['id', 'product_id', 'currency_id', 'currency', 'price'], array_keys($data));
        $this->assertSame('1199.00', $data['price']);
    }

    public function test_currency_is_omitted_when_the_relation_is_not_loaded(): void
    {
        $price = ProductPrice::factory()->make(['id' => 7, 'product_id' => 21, 'currency_id' => 2, 'price' => '1199']);

        $this->assertArrayNotHasKey('currency', (new ProductPriceResource($price))->resolve());
    }

    public function test_timestamps_are_not_exposed(): void
    {
        $price = ProductPrice::factory()->make(['id' => 7, 'product_id' => 21, 'currency_id' => 2, 'price' => '1']);
        $price->created_at = now();
        $price->updated_at = now();

        $data = (new ProductPriceResource($price))->resolve();

        $this->assertArrayNotHasKey('created_at', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
    }
}
