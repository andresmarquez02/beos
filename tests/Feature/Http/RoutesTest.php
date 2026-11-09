<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_only_the_documented_routes_exist(): void
    {
        $uris = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route): string => $route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'api/v1/products',
            'api/v1/products/{product}',
            'api/v1/products/{product}/prices',
            'docs/api',
            'docs/api.json',
            'up',
        ], $uris);
    }

    public function test_sanctum_csrf_cookie_endpoint_is_not_exposed_and_sets_no_cookies(): void
    {
        $response = $this->get('/sanctum/csrf-cookie')->assertNotFound();

        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_storage_files_are_not_served(): void
    {
        $this->get('/storage/anything.txt')->assertNotFound();
    }

    public function test_api_responses_never_set_cookies(): void
    {
        foreach ([
            $this->postJson('/api/v1/products', []),
            $this->getJson('/api/v1/products'),
            $this->getJson('/api/v1/nope'),
        ] as $response) {
            $this->assertEmpty($response->headers->getCookies());
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
    }
}
