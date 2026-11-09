<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_uuid_is_generated_when_the_client_sends_none(): void
    {
        $response = $this->getJson('/api/v1/products');

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }

    public function test_a_valid_incoming_request_id_is_kept_and_echoed_in_the_error_body(): void
    {
        $id = '9f1c2b7e-3c1a-4b8e-9d52-1c0a7e5d1f10';

        $this->withHeader('X-Request-Id', $id)->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', $id)
            ->assertJsonPath('request_id', $id);
    }

    public function test_an_invalid_incoming_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-Id', "not-a-uuid\r\nInjected: 1")->getJson('/api/v1/products');

        $id = (string) $response->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($id));
        $response->assertJsonPath('request_id', $id);
    }

    public function test_request_id_is_present_on_unknown_routes_and_success_responses(): void
    {
        $this->assertTrue(Str::isUuid((string) $this->call('GET', '/api/v1/nope')->headers->get('X-Request-Id')));
        $this->assertTrue(Str::isUuid((string) $this->get('/up')->headers->get('X-Request-Id')));
    }

    public function test_each_request_gets_a_different_id(): void
    {
        $first = $this->getJson('/api/v1/products')->headers->get('X-Request-Id');
        $second = $this->getJson('/api/v1/products')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }
}
