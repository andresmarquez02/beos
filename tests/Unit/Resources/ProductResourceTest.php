<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Http\Resources\V1\ProductResource;
use App\Models\Currency;
use App\Models\Product;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    public function test_exposes_exactly_the_whitelisted_fields(): void
    {
        $product = $this->product();
        $product->setRelation('currency', Currency::factory()->make(['id' => 1, 'code' => 'USD']));

        $data = (new ProductResource($product))->resolve();

        $this->assertSame(
            ['id', 'name', 'description', 'price', 'currency_id', 'currency', 'tax_cost', 'manufacturing_cost', 'created_at', 'updated_at'],
            array_keys($data),
        );
        $this->assertSame('1299.90', $data['price']);
        $this->assertSame('249.00', $data['tax_cost']);
        $this->assertSame('800.00', $data['manufacturing_cost']);
    }

    public function test_embedded_currency_is_a_whitelisted_projection(): void
    {
        $product = $this->product();
        $product->setRelation('currency', Currency::factory()->make(['id' => 1, 'exchange_rate' => '1']));

        $currency = json_decode((new ProductResource($product))->toResponse(request())->getContent(), true)['data']['currency'];

        $this->assertSame(['id', 'code', 'name', 'symbol', 'exchange_rate'], array_keys($currency));
        $this->assertSame('1.000000', $currency['exchange_rate']);
    }

    public function test_currency_is_omitted_when_the_relation_is_not_loaded(): void
    {
        $data = (new ProductResource($this->product()))->resolve();

        $this->assertArrayNotHasKey('currency', $data);
        $this->assertArrayHasKey('currency_id', $data);
    }

    public function test_never_exposes_deleted_at(): void
    {
        $product = $this->product();
        $product->deleted_at = now();

        $this->assertArrayNotHasKey('deleted_at', (new ProductResource($product))->resolve());
    }

    private function product(): Product
    {
        return Product::factory()->make([
            'id' => 5,
            'currency_id' => 1,
            'price' => '1299.9',
            'tax_cost' => '249',
            'manufacturing_cost' => '800',
        ]);
    }
}
