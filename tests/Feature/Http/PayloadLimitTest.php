<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class PayloadLimitTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_body_above_the_limit_returns_413_json(): void
    {
        $this->actingAsAdmin();

        $response = $this->call('POST', '/api/v1/products', server: ['CONTENT_TYPE' => 'application/json'], content: $this->body(64 * 1024 + 1));

        $response->assertStatus(413)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'payload_too_large')
            ->assertJsonPath('message', 'Payload too large.')
            ->assertJsonStructure(['message', 'code', 'request_id']);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_oversized_declared_content_length_is_rejected_before_authentication(): void
    {
        $this->call('POST', '/api/v1/products', server: ['CONTENT_TYPE' => 'application/json'], content: $this->body(64 * 1024 + 1))
            ->assertStatus(413)
            ->assertJsonPath('code', 'payload_too_large');
    }

    public function test_limit_follows_configuration(): void
    {
        config(['api.max_payload_kb' => 1]);

        $this->call('POST', '/api/v1/products', server: ['CONTENT_TYPE' => 'application/json'], content: $this->body(2048))
            ->assertStatus(413);
    }

    public function test_body_within_the_limit_reaches_the_application(): void
    {
        $this->actingAsAdmin();

        $this->call('POST', '/api/v1/products', server: ['CONTENT_TYPE' => 'application/json'], content: $this->body(1024))
            ->assertUnprocessable();
    }

    private function body(int $bytes): string
    {
        $overhead = strlen((string) json_encode(['padding' => '']));

        return (string) json_encode(['padding' => str_repeat('a', $bytes - $overhead)]);
    }
}
