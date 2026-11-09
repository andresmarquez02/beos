<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\Concerns\ProductPayloads;
use Tests\TestCase;

class UpdateProductTest extends TestCase
{
    use ActsAsApiUser;
    use ProductPayloads;
    use RefreshDatabase;

    public function test_admin_replaces_the_product(): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create(['name' => 'Old']);
        $this->actingAsAdmin();

        $this->travel(5)->minutes();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($currency, ['name' => 'New', 'description' => null]))
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.price', '1299.90')
            ->assertJsonPath('data.currency.id', $currency->id)
            ->assertJsonMissingPath('data.deleted_at');

        $fresh = $product->fresh();
        $this->assertSame('New', $fresh->name);
        $this->assertTrue($fresh->updated_at->gt($product->updated_at));
    }

    public function test_changing_base_currency_reloads_the_embedded_currency(): void
    {
        $old = Currency::factory()->create();
        $new = Currency::factory()->create(['code' => 'ZZZ']);
        $product = Product::factory()->for($old)->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($new))
            ->assertOk()
            ->assertJsonPath('data.currency_id', $new->id)
            ->assertJsonPath('data.currency.code', 'ZZZ');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'currency_id' => $new->id]);
    }

    public function test_cannot_switch_base_currency_to_one_with_a_registered_price(): void
    {
        $old = Currency::factory()->create();
        $withPrice = Currency::factory()->create();
        $product = Product::factory()->for($old)->create(['name' => 'Untouched']);
        ProductPrice::factory()->for($product)->for($withPrice)->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($withPrice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency_id');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Untouched', 'currency_id' => $old->id]);
    }

    public function test_keeping_the_same_base_currency_skips_the_price_rule(): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create();
        ProductPrice::factory()->for($product)->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($currency))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $remove
     */
    #[DataProvider('invalidProductPayloadProvider')]
    public function test_invalid_payload_returns_422_and_row_is_unchanged(array $overrides, array $remove, string $field): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create(['name' => 'Untouched']);
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->invalidProductPayload($currency, $overrides, $remove))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Untouched']);
    }

    public function test_partial_update_is_not_supported(): void
    {
        $product = Product::factory()->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Only name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'currency_id', 'tax_cost', 'manufacturing_cost']);
    }

    public function test_server_generated_fields_are_ignored(): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($currency, [
            'id' => 987654,
            'created_at' => '2000-01-01T00:00:00Z',
            'deleted_at' => '2000-01-01T00:00:00Z',
        ]))->assertOk()->assertJsonPath('data.id', $product->id);

        $fresh = $product->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
        $this->assertTrue($fresh->created_at->eq($product->created_at));
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->putJson('/api/v1/products/'.$product->id, [])->assertUnauthorized();
    }

    public function test_viewer_is_forbidden_even_with_an_invalid_body(): void
    {
        $product = Product::factory()->create(['name' => 'Untouched']);
        $this->actingAsViewer();

        $this->putJson('/api/v1/products/'.$product->id, [])->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Untouched']);
    }

    public function test_admin_token_without_write_ability_is_forbidden(): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create();
        $this->actingWithAbilities(['products:read'], UserRole::Admin);

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($currency))->assertForbidden();
    }

    public function test_unknown_product_returns_404_before_validation(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/999999', [])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_soft_deleted_product_returns_404(): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create();
        $product->delete();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/'.$product->id, $this->validProductPayload($currency))->assertNotFound();
    }

    public function test_malformed_id_returns_404(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/products/abc', $this->validProductPayload($currency))->assertNotFound();
        $this->putJson('/api/v1/products/99999999999999999999', $this->validProductPayload($currency))->assertNotFound();
    }
}
