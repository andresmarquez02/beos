<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\Concerns\ProductPayloads;
use Tests\TestCase;

class StoreProductTest extends TestCase
{
    use ActsAsApiUser;
    use ProductPayloads;
    use RefreshDatabase;

    private const URL = '/api/v1/products';

    public function test_admin_creates_a_product(): void
    {
        $currency = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => '1']);
        $this->actingAsAdmin();

        $response = $this->postJson(self::URL, $this->validProductPayload($currency));

        $product = Product::query()->sole();

        $response->assertCreated()
            ->assertHeader('Location', '/api/v1/products/'.$product->id)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'description', 'price', 'currency_id',
                'currency' => ['id', 'code', 'name', 'symbol', 'exchange_rate'],
                'tax_cost', 'manufacturing_cost', 'created_at', 'updated_at',
            ]])
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Laptop Pro 14')
            ->assertJsonPath('data.price', '1299.90')
            ->assertJsonPath('data.tax_cost', '249.00')
            ->assertJsonPath('data.manufacturing_cost', '800.00')
            ->assertJsonPath('data.currency.exchange_rate', '1.000000')
            ->assertJsonMissingPath('data.deleted_at');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Laptop Pro 14',
            'price' => '1299.90',
            'currency_id' => $currency->id,
            'tax_cost' => '249.00',
            'manufacturing_cost' => '800.00',
        ]);
    }

    public function test_accepts_json_numbers_and_null_description(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->validProductPayload($currency, [
            'description' => null,
            'price' => 19.99,
            'tax_cost' => 0,
            'manufacturing_cost' => 12.5,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.price', '19.99')
            ->assertJsonPath('data.tax_cost', '0.00')
            ->assertJsonPath('data.manufacturing_cost', '12.50');
    }

    public function test_name_is_trimmed(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->validProductPayload($currency, ['name' => '  Spaced  ']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Spaced');
    }

    public function test_accepts_maximum_amount_sent_as_string(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->validProductPayload($currency, ['price' => '9999999999999.99']))
            ->assertCreated()
            ->assertJsonPath('data.price', '9999999999999.99');
    }

    public function test_server_generated_fields_are_not_mass_assignable(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $response = $this->postJson(self::URL, $this->validProductPayload($currency, [
            'id' => 987654,
            'created_at' => '2000-01-01T00:00:00Z',
            'deleted_at' => '2000-01-01T00:00:00Z',
            'is_admin' => true,
        ]));

        $response->assertCreated();
        $product = Product::query()->sole();
        $this->assertNotSame(987654, $product->id);
        $this->assertNull($product->deleted_at);
        $this->assertTrue($product->created_at->isToday());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $remove
     */
    #[DataProvider('invalidProductPayloadProvider')]
    public function test_invalid_payload_returns_422_and_creates_nothing(array $overrides, array $remove, string $field): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->postJson(self::URL, $this->invalidProductPayload($currency, $overrides, $remove))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonValidationErrors($field)
            ->assertJsonStructure(['message', 'code', 'errors', 'request_id']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_empty_body_reports_every_required_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'currency_id', 'tax_cost', 'manufacturing_cost']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_viewer_is_forbidden_and_nothing_is_created(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsViewer();

        $this->postJson(self::URL, $this->validProductPayload($currency))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_authorization_is_evaluated_before_validation(): void
    {
        $this->actingAsViewer();

        $this->postJson(self::URL, ['name' => ''])->assertForbidden();
    }

    public function test_admin_token_without_write_ability_is_forbidden(): void
    {
        $currency = Currency::factory()->create();
        $this->actingWithAbilities(['products:read'], UserRole::Admin);

        $this->postJson(self::URL, $this->validProductPayload($currency))->assertForbidden();
    }

    public function test_viewer_role_cannot_write_even_with_a_write_ability_token(): void
    {
        $currency = Currency::factory()->create();
        $this->actingWithAbilities(['products:read', 'products:write'], UserRole::Viewer);

        $this->postJson(self::URL, $this->validProductPayload($currency))->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }
}
