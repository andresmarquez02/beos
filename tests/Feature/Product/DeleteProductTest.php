<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class DeleteProductTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_admin_soft_deletes_the_product_and_keeps_its_prices(): void
    {
        $product = Product::factory()->create();
        ProductPrice::factory()->for($product)->count(2)->create();
        $this->actingAsAdmin();

        $response = $this->deleteJson('/api/v1/products/'.$product->id);

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_prices', 2);
    }

    public function test_deleted_product_and_its_prices_are_no_longer_reachable(): void
    {
        $product = Product::factory()->create();
        ProductPrice::factory()->for($product)->create();
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/products/'.$product->id)->assertNoContent();

        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/v1/products/'.$product->id.'/prices')->assertNotFound();
        $this->deleteJson('/api/v1/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->deleteJson('/api/v1/products/'.$product->id)->assertUnauthorized();
    }

    public function test_viewer_is_forbidden_and_product_is_kept(): void
    {
        $product = Product::factory()->create();
        $this->actingAsViewer();

        $this->deleteJson('/api/v1/products/'.$product->id)->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_admin_token_without_write_ability_is_forbidden(): void
    {
        $product = Product::factory()->create();
        $this->actingWithAbilities(['products:read'], UserRole::Admin);

        $this->deleteJson('/api/v1/products/'.$product->id)->assertForbidden();
    }

    public function test_unknown_and_malformed_ids_return_404(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/products/999999')->assertNotFound();
        $this->deleteJson('/api/v1/products/abc')->assertNotFound();
        $this->deleteJson('/api/v1/products/0')->assertNotFound();
    }
}
