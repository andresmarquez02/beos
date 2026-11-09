<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Currency;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\Concerns\ProductPayloads;
use Tests\TestCase;

class MalformedInputTest extends TestCase
{
    use ActsAsApiUser;
    use ProductPayloads;
    use RefreshDatabase;

    private const INVALID_UTF8 = "ab\xFF\xFEcd";

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeTextProvider(): array
    {
        return [
            'invalid utf-8' => [self::INVALID_UTF8],
            'null byte' => ["ab\0cd"],
            'truncated multibyte sequence' => ["ab\xE2\x82"],
        ];
    }

    #[DataProvider('unsafeTextProvider')]
    public function test_search_with_unsafe_text_returns_422(string $text): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/v1/products?search='.rawurlencode($text))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_search_with_percent_encoded_invalid_utf8_returns_422_not_500(): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/v1/products?search=%ff%fe')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_error');
    }

    #[DataProvider('unsafeTextProvider')]
    public function test_store_product_with_unsafe_name_or_description_returns_422(string $text): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        foreach (['name', 'description'] as $field) {
            $this->call('POST', '/api/v1/products', $this->validProductPayload($currency, [$field => $text]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_product_with_null_byte_in_json_is_rejected_instead_of_silently_truncated(): void
    {
        $currency = Currency::factory()->create();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/products', $this->validProductPayload($currency, ['name' => "ab\0cd"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/products', $this->validProductPayload($currency, ['description' => "ab\0cd"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('unsafeTextProvider')]
    public function test_update_product_with_unsafe_text_returns_422_and_row_is_unchanged(string $text): void
    {
        $currency = Currency::factory()->create();
        $product = Product::factory()->for($currency)->create(['name' => 'Untouched']);
        $this->actingAsAdmin();

        $this->call('PUT', '/api/v1/products/'.$product->id, $this->validProductPayload($currency, ['name' => $text]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Untouched']);
    }

    public function test_invalid_utf8_in_the_path_returns_400_not_500(): void
    {
        $this->actingAsViewer();

        $response = $this->call('GET', '/api/v1/products/%ff');
        $response->assertHeader('X-Request-Id')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/json');

        $this->call('GET', '/api/v1/products/%ff')->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->call('GET', '/api/v1/%ff%fe')->assertStatus(400);
    }

    public function test_unauthenticated_request_with_invalid_utf8_in_the_path_returns_400_json(): void
    {
        $this->call('GET', '/api/v1/%ff%fe/x')->assertStatus(400)->assertJsonPath('code', 'bad_request');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedJsonProvider(): array
    {
        return [
            'truncated object' => ['POST', '{"name": '],
            'trailing garbage' => ['POST', '{"name":"x"}}'],
            'single quotes' => ['POST', "{'name':'x'}"],
            'bare word' => ['POST', 'not json'],
            'invalid utf-8 inside json' => ['POST', "{\"name\":\"\xFF\"}"],
        ];
    }

    #[DataProvider('malformedJsonProvider')]
    public function test_malformed_json_body_returns_400_bad_request(string $method, string $body): void
    {
        $this->actingAsAdmin();

        $response = $this->call($method, '/api/v1/products', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'bad_request')
            ->assertJsonPath('message', 'Bad request.')
            ->assertJsonMissingPath('errors')
            ->assertJsonStructure(['message', 'code', 'request_id']);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function test_malformed_json_is_rejected_on_every_endpoint_with_a_body(): void
    {
        $product = Product::factory()->create();
        $this->actingAsAdmin();
        $json = ['CONTENT_TYPE' => 'application/json'];

        $this->call('PUT', '/api/v1/products/'.$product->id, server: $json, content: '{bad')->assertStatus(400);
        $this->call('POST', '/api/v1/products/'.$product->id.'/prices', server: $json, content: '{bad')->assertStatus(400);
    }

    public function test_valid_json_and_empty_json_bodies_are_still_handled_by_validation(): void
    {
        $this->actingAsAdmin();
        $json = ['CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/v1/products', server: $json, content: '')->assertUnprocessable();
        $this->call('POST', '/api/v1/products', server: $json, content: '{}')->assertUnprocessable();
        $this->call('POST', '/api/v1/products', server: $json, content: '[]')->assertUnprocessable();
        $this->call('GET', '/api/v1/products', server: $json)->assertOk();
    }

    public function test_non_json_content_types_are_not_inspected(): void
    {
        $this->actingAsAdmin();

        $this->call('POST', '/api/v1/products', server: ['CONTENT_TYPE' => 'text/plain'], content: '{bad')->assertUnprocessable();
    }
}
