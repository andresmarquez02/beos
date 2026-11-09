<?php

declare(strict_types=1);

namespace Tests\Feature\ProductPrice;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class StorePriceTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_admin_registers_a_price_in_another_currency(): void
    {
        $usd = Currency::factory()->create();
        $eur = Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.92']);
        $product = Product::factory()->for($usd)->create();
        $this->actingAsAdmin();

        $response = $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '1199.00']);

        $price = ProductPrice::query()->sole();

        $response->assertCreated()
            ->assertHeader('Location', '/api/v1/products/'.$product->id.'/prices')
            ->assertExactJson(['data' => [
                'id' => $price->id,
                'product_id' => $product->id,
                'currency_id' => $eur->id,
                'currency' => ['id' => $eur->id, 'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.920000'],
                'price' => '1199.00',
            ]]);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'currency_id' => $eur->id,
            'price' => '1199.00',
        ]);
    }

    public function test_product_id_in_body_is_ignored(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $this->actingAsAdmin();

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => 10, 'product_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.product_id', $product->id);

        $this->assertDatabaseHas('product_prices', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('product_prices', ['product_id' => $other->id]);
    }

    public function test_duplicate_price_returns_409_and_keeps_the_existing_one(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        ProductPrice::factory()->for($product)->for($eur)->create(['price' => '100.00']);
        $this->actingAsAdmin();

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '999.00'])
            ->assertConflict()
            ->assertJsonPath('code', 'conflict')
            ->assertJsonPath('message', 'A price already exists for this product and currency.')
            ->assertJsonMissingPath('errors')
            ->assertJsonStructure(['message', 'code', 'request_id']);

        $this->assertDatabaseCount('product_prices', 1);
        $this->assertDatabaseHas('product_prices', ['price' => '100.00']);
    }

    public function test_unique_violation_raised_by_a_concurrent_insert_maps_to_409(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $this->actingAsAdmin();

        $repository = $this->createMock(ProductPriceRepositoryInterface::class);
        $repository->method('existsForProductAndCurrency')->willReturn(false);
        $repository->method('create')->willThrowException(
            new UniqueConstraintViolationException('pgsql', 'insert into product_prices', [], new Exception('duplicate key')),
        );
        $this->app->instance(ProductPriceRepositoryInterface::class, $repository);

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '10.00'])
            ->assertConflict()
            ->assertJsonPath('code', 'conflict');
    }

    public function test_base_currency_returns_422_on_currency_id(): void
    {
        $usd = Currency::factory()->create();
        $product = Product::factory()->for($usd)->create();
        $this->actingAsAdmin();

        $this->postJson($this->url($product), ['currency_id' => $usd->id, 'price' => '10.00'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonValidationErrors('currency_id');

        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_body_validation_runs_before_the_conflict_check(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        ProductPrice::factory()->for($product)->for($eur)->create();
        $this->actingAsAdmin();

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'currency missing' => [['price' => '10.00'], 'currency_id'],
            'price missing' => [['currency_id' => '__valid__'], 'price'],
            'currency unknown' => [['currency_id' => 999999, 'price' => '10.00'], 'currency_id'],
            'currency not an integer' => [['currency_id' => 'abc', 'price' => '10.00'], 'currency_id'],
            'currency beyond bigint' => [['currency_id' => '99999999999999999999', 'price' => '10.00'], 'currency_id'],
            'price zero' => [['currency_id' => '__valid__', 'price' => 0], 'price'],
            'price negative' => [['currency_id' => '__valid__', 'price' => '-1'], 'price'],
            'price three decimals' => [['currency_id' => '__valid__', 'price' => '1.234'], 'price'],
            'price not numeric' => [['currency_id' => '__valid__', 'price' => 'abc'], 'price'],
            'price above max' => [['currency_id' => '__valid__', 'price' => '10000000000000.00'], 'price'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_returns_422(array $payload, string $field): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $this->actingAsAdmin();

        $payload = array_map(fn (mixed $value): mixed => $value === '__valid__' ? $eur->id : $value, $payload);

        $this->postJson($this->url($product), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->postJson($this->url($product), [])->assertUnauthorized();
    }

    public function test_viewer_is_forbidden_even_with_an_invalid_body(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $this->actingAsViewer();

        $this->postJson($this->url($product), [])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '10.00'])->assertForbidden();

        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_admin_token_without_write_ability_is_forbidden(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $this->actingWithAbilities(['products:read'], UserRole::Admin);

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '10.00'])->assertForbidden();
    }

    public function test_unknown_product_returns_404_before_validation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/products/999999/prices', [])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_soft_deleted_product_returns_404(): void
    {
        $eur = Currency::factory()->create();
        $product = Product::factory()->create();
        $product->delete();
        $this->actingAsAdmin();

        $this->postJson($this->url($product), ['currency_id' => $eur->id, 'price' => '10.00'])->assertNotFound();
    }

    private function url(Product $product): string
    {
        return '/api/v1/products/'.$product->id.'/prices';
    }
}
