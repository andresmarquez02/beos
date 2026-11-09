<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class DocsTest extends TestCase
{
    public function test_ui_is_served_when_docs_are_enabled(): void
    {
        config(['api.docs_enabled' => true]);

        $this->get('/docs/api')->assertOk();
    }

    public function test_ui_and_spec_are_forbidden_when_docs_are_disabled_outside_local(): void
    {
        config(['api.docs_enabled' => false]);

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_spec_documents_every_api_route_with_bearer_security_and_error_schema(): void
    {
        config(['api.docs_enabled' => true]);

        $spec = $this->getJson('/docs/api.json')->assertOk()->json();

        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $this->assertSame('http://localhost/api/v1', $spec['servers'][0]['url']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['http']['scheme']);
        $this->assertArrayHasKey('Error', $spec['components']['schemas']);
        $this->assertArrayHasKey('ValidationError', $spec['components']['schemas']);
        $this->assertArrayNotHasKey('/auth/login', $spec['paths']);

        $documented = [];
        foreach ($spec['paths'] as $uri => $operations) {
            foreach ($operations as $method => $operation) {
                $documented[] = strtoupper($method).' api/v1'.preg_replace('/\{[^}]+\}/', '{}', $uri);
                $this->assertArrayNotHasKey('security', $operation, 'Every operation inherits the global bearer requirement.');
            }
        }

        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route): array => array_map(
                fn (string $method): string => $method.' '.preg_replace('/\{[^}]+\}/', '{}', $route->uri()),
                array_values(array_diff($route->methods(), ['HEAD'])),
            ))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($routes, $documented);
        $this->assertCount(7, $routes);
        $this->assertSame([['http' => []]], $spec['security']);
    }

    public function test_matrix_error_codes_are_documented_per_operation(): void
    {
        config(['api.docs_enabled' => true]);

        $spec = $this->getJson('/docs/api.json')->json();
        $codes = fn (string $path, string $method): array => array_keys($spec['paths'][$path][$method]['responses']);

        $this->assertEqualsCanonicalizing(['201', '400', '401', '403', '404', '409', '413', '422', '429'], $codes('/products/{product}/prices', 'post'));
        $this->assertEqualsCanonicalizing(['200', '401', '403', '404', '429'], $codes('/products/{product}/prices', 'get'));
        $this->assertEqualsCanonicalizing(['200', '401', '403', '422', '429'], $codes('/products', 'get'));
        $this->assertEqualsCanonicalizing(['201', '400', '401', '403', '413', '422', '429'], $codes('/products', 'post'));
        $this->assertEqualsCanonicalizing(['204', '401', '403', '404', '429'], $codes('/products/{product}', 'delete'));
    }
}
