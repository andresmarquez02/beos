<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function viewerProvider(): array
    {
        return [
            'products index' => ['GET', '/api/v1/products', false],
            'products store' => ['POST', '/api/v1/products', true],
            'products show' => ['GET', '/api/v1/products/{id}', false],
            'products update' => ['PUT', '/api/v1/products/{id}', true],
            'products destroy' => ['DELETE', '/api/v1/products/{id}', true],
            'prices index' => ['GET', '/api/v1/products/{id}/prices', false],
            'prices store' => ['POST', '/api/v1/products/{id}/prices', true],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointProvider(): array
    {
        return array_map(fn (array $case): array => [$case[0], $case[1]], self::viewerProvider());
    }

    #[DataProvider('endpointProvider')]
    public function test_every_endpoint_requires_a_valid_token(string $method, string $uri): void
    {
        $uri = str_replace('{id}', (string) Product::factory()->create()->id, $uri);

        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->withToken('1|not-a-real-token')->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('endpointProvider')]
    public function test_expired_and_revoked_tokens_are_rejected_on_every_endpoint(string $method, string $uri): void
    {
        $uri = str_replace('{id}', (string) Product::factory()->create()->id, $uri);
        $user = User::factory()->admin()->create();

        $expired = $user->createToken('expired', ['products:read', 'products:write'], now()->subMinute())->plainTextToken;
        $revoked = $user->createToken('revoked', ['products:read', 'products:write'], now()->addHour());
        $revoked->accessToken->delete();

        $this->withToken($expired)->json($method, $uri)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($revoked->plainTextToken)->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('viewerProvider')]
    public function test_viewer_token_is_forbidden_on_write_endpoints_even_with_an_invalid_body(string $method, string $uri, bool $writes): void
    {
        $uri = str_replace('{id}', (string) Product::factory()->create()->id, $uri);
        $token = User::factory()->viewer()->create()->createToken('viewer', ['products:read'], now()->addHour())->plainTextToken;

        $response = $this->withToken($token)->json($method, $uri, []);

        if ($writes) {
            $response->assertForbidden()->assertJsonPath('code', 'forbidden');
        } else {
            $response->assertOk();
        }
    }

    public function test_token_without_the_read_ability_is_forbidden_everywhere(): void
    {
        $product = Product::factory()->create();
        $token = User::factory()->admin()->create()->createToken('empty', [], now()->addHour())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/products/'.$product->id)->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/products/'.$product->id.'/prices')->assertForbidden();
    }

    public function test_admin_token_with_read_only_ability_cannot_write(): void
    {
        $token = User::factory()->admin()->create()->createToken('api', ['products:read'], now()->addHour())->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/products', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_tokens_do_not_expire_by_creation_date_when_expires_at_is_null(): void
    {
        $token = User::factory()->viewer()->create()->createToken('demo', ['products:read'])->plainTextToken;

        $this->travel(400)->days();

        $this->withToken($token)->getJson('/api/v1/products')->assertOk();
    }

    public function test_tokens_expire_at_their_expires_at(): void
    {
        $token = User::factory()->viewer()->create()->createToken('short', ['products:read'], now()->addMinutes(10))->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(11)->minutes();

        $this->withToken($token)->getJson('/api/v1/products')->assertUnauthorized();
    }
}
