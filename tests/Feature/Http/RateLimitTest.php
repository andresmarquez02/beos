<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'products index' => ['GET', '/api/v1/products'],
            'products store' => ['POST', '/api/v1/products'],
            'products show' => ['GET', '/api/v1/products/{id}'],
            'products update' => ['PUT', '/api/v1/products/{id}'],
            'products destroy' => ['DELETE', '/api/v1/products/{id}'],
            'prices index' => ['GET', '/api/v1/products/{id}/prices'],
            'prices store' => ['POST', '/api/v1/products/{id}/prices'],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function test_authenticated_endpoints_are_throttled_per_user(string $method, string $uri): void
    {
        config(['api.rate_limits.api' => 1]);
        $product = Product::factory()->create();
        $this->actingAsAdmin();
        $uri = str_replace('{id}', (string) $product->id, $uri);

        $this->json($method, $uri);

        $this->json($method, $uri)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonPath('message', 'Too many requests.')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonStructure(['message', 'code', 'request_id']);
    }

    public function test_successful_responses_expose_rate_limit_headers(): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');
    }

    public function test_limit_is_independent_for_each_user(): void
    {
        config(['api.rate_limits.api' => 1]);

        $this->actingAsViewer();
        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products')->assertTooManyRequests();

        $this->actingAsViewer();
        $this->getJson('/api/v1/products')->assertOk();
    }
}
