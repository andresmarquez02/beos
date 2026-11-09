<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_preflight_from_an_allowed_origin_is_accepted(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/products', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Max-Age', '600');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_from_an_unknown_origin_is_never_granted_to_that_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/products', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotContains($response->headers->get('Access-Control-Allow-Origin'), ['https://evil.example', '*']);
    }

    public function test_actual_response_exposes_the_configured_headers_to_allowed_origins(): void
    {
        $response = $this->call('GET', '/api/v1/nope', server: ['HTTP_ORIGIN' => 'http://localhost:3000']);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString('X-Request-Id', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_wildcard_origin_is_never_configured(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertSame(['api/*'], config('cors.paths'));
    }
}
