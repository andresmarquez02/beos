<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Exceptions\ConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_validation_error_uses_the_unified_format(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/products', []);

        $response->assertUnprocessable()
            ->assertJsonStructure(['message', 'code', 'errors' => ['name'], 'request_id'])
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('request_id', $response->headers->get('X-Request-Id'));
        $this->assertSame(['message', 'code', 'errors', 'request_id'], array_keys($response->json()));
    }

    public function test_unauthenticated_error_format(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
                'request_id' => $response->headers->get('X-Request-Id'),
            ])
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_forbidden_error_format(): void
    {
        $this->actingAsViewer();

        $response = $this->postJson('/api/v1/products', []);

        $response->assertForbidden()
            ->assertExactJson([
                'message' => 'This action is unauthorized.',
                'code' => 'forbidden',
                'request_id' => $response->headers->get('X-Request-Id'),
            ]);
    }

    public function test_unknown_route_returns_json_404_without_accept_header(): void
    {
        $response = $this->call('GET', '/api/v1/nope');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => 'Resource not found.',
                'code' => 'not_found',
                'request_id' => $response->headers->get('X-Request-Id'),
            ]);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_method_not_allowed_returns_json_405_with_allow_header(): void
    {
        $response = $this->call('PATCH', '/api/v1/products/1');

        $response->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'method_not_allowed')
            ->assertJsonPath('message', 'Method not allowed.');
        $this->assertStringContainsString('PUT', (string) $response->headers->get('Allow'));
    }

    public function test_json_is_returned_even_when_client_asks_for_html(): void
    {
        $this->actingAsAdmin();

        $this->call('GET', '/api/v1/products/999999', server: ['HTTP_ACCEPT' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_unexpected_errors_return_generic_500_without_internals_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Route::get('api/v1/_boom', fn () => throw new RuntimeException('SQLSTATE secret C:\\internal\\path'));
        Log::spy();

        $response = $this->call('GET', '/api/v1/_boom');

        $response->assertStatus(500)
            ->assertExactJson([
                'message' => 'Server error.',
                'code' => 'server_error',
                'request_id' => $response->headers->get('X-Request-Id'),
            ]);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('internal', $response->getContent());
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'SQLSTATE secret'));
        foreach (['trace', 'file', 'line', 'exception'] as $key) {
            $response->assertJsonMissingPath($key);
        }
    }

    public function test_conflicts_are_rendered_as_409_but_not_reported_as_errors(): void
    {
        Route::post('api/v1/_conflict', fn () => throw new ConflictException('Already there.'));
        Log::spy();

        $this->call('POST', '/api/v1/_conflict')
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflict')
            ->assertJsonPath('message', 'Already there.');

        Log::shouldNotHaveReceived('error');
    }

    public function test_non_api_routes_keep_default_rendering(): void
    {
        $this->get('/does-not-exist')->assertNotFound();
        $this->get('/up')->assertOk();
    }

    public function test_rejections_are_logged_with_request_context_but_without_secrets(): void
    {
        Log::spy();

        $this->withToken('1|secret-token')->getJson('/api/v1/products')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['code'] === 'unauthenticated'
                && $context['status'] === 401
                && $context['method'] === 'GET'
                && $context['path'] === 'api/v1/products'
                && array_key_exists('ip', $context)
                && array_key_exists('user_id', $context)
                && ! str_contains(json_encode($context), 'secret-token'),
        );
    }
}
