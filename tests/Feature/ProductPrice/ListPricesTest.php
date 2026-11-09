<?php

declare(strict_types=1);

namespace Tests\Feature\ProductPrice;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class ListPricesTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_lists_additional_prices_ordered_by_currency_code(): void
    {
        $usd = Currency::factory()->create(['code' => 'USD']);
        $eur = Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.92']);
        $cop = Currency::factory()->create(['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'exchange_rate' => '4100']);
        $product = Product::factory()->for($usd)->create();
        $eurPrice = ProductPrice::factory()->for($product)->for($eur)->create(['price' => '1199.00']);
        $copPrice = ProductPrice::factory()->for($product)->for($cop)->create(['price' => '5000000']);
        ProductPrice::factory()->for(Product::factory()->for($usd))->for($eur)->create();
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/'.$product->id.'/prices')
            ->assertOk()
            ->assertExactJson(['data' => [
                [
                    'id' => $copPrice->id,
                    'product_id' => $product->id,
                    'currency_id' => $cop->id,
                    'currency' => ['id' => $cop->id, 'code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'exchange_rate' => '4100.000000'],
                    'price' => '5000000.00',
                ],
                [
                    'id' => $eurPrice->id,
                    'product_id' => $product->id,
                    'currency_id' => $eur->id,
                    'currency' => ['id' => $eur->id, 'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.920000'],
                    'price' => '1199.00',
                ],
            ]]);
    }

    public function test_product_without_additional_prices_returns_empty_list(): void
    {
        $product = Product::factory()->create();
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/'.$product->id.'/prices')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_number_of_queries_does_not_depend_on_number_of_prices(): void
    {
        $product = Product::factory()->create();
        $this->actingAsViewer();

        ProductPrice::factory()->for($product)->count(2)->create();
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/products/'.$product->id.'/prices')->assertJsonCount(2, 'data'));

        ProductPrice::factory()->for($product)->count(8)->create();
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/products/'.$product->id.'/prices')->assertJsonCount(10, 'data'));

        $this->assertSame($few, $many);
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->id.'/prices')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_token_without_read_ability_is_forbidden(): void
    {
        $product = Product::factory()->create();
        $this->actingWithAbilities([], UserRole::Viewer);

        $this->getJson('/api/v1/products/'.$product->id.'/prices')->assertForbidden();
    }

    public function test_unknown_deleted_and_malformed_products_return_404(): void
    {
        $deleted = Product::factory()->create();
        $deleted->delete();
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/999999/prices')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/products/'.$deleted->id.'/prices')->assertNotFound();
        $this->getJson('/api/v1/products/abc/prices')->assertNotFound();
        $this->getJson('/api/v1/products/99999999999999999999/prices')->assertNotFound();
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
