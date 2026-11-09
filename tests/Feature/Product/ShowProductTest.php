<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class ShowProductTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_returns_the_product_with_exact_structure(): void
    {
        $currency = Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => '0.92']);
        $product = Product::factory()->for($currency)->create([
            'name' => 'Laptop',
            'description' => null,
            'price' => '1299.90',
            'tax_cost' => '249',
            'manufacturing_cost' => '800.5',
        ]);
        $product = $product->fresh();
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $product->id,
                'name' => 'Laptop',
                'description' => null,
                'price' => '1299.90',
                'currency_id' => $currency->id,
                'currency' => [
                    'id' => $currency->id,
                    'code' => 'EUR',
                    'name' => 'Euro',
                    'symbol' => '€',
                    'exchange_rate' => '0.920000',
                ],
                'tax_cost' => '249.00',
                'manufacturing_cost' => '800.50',
                'created_at' => $product->created_at->toJSON(),
                'updated_at' => $product->updated_at->toJSON(),
            ]]);
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_token_without_read_ability_is_forbidden(): void
    {
        $product = Product::factory()->create();
        $this->actingWithAbilities([], UserRole::Viewer);

        $this->getJson('/api/v1/products/'.$product->id)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_unknown_product_returns_404_without_leaking_details(): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Resource not found.')
            ->assertJsonMissingPath('errors');
    }

    public function test_soft_deleted_product_returns_404(): void
    {
        $product = Product::factory()->create();
        $product->delete();
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedIdProvider(): array
    {
        return [
            'letters' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'leading zero' => ['01'],
            'beyond bigint' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('malformedIdProvider')]
    public function test_malformed_id_returns_404_not_500(string $id): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/v1/products/'.$id)->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
