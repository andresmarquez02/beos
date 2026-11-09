<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class TrustedHostTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    public function test_untrusted_host_is_rejected_with_a_json_400(): void
    {
        $this->actingAsViewer();

        $response = $this->getJson('http://evil.test/api/v1/products');

        $response->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->assertStringNotContainsString('evil.test', (string) $response->getContent());
    }

    public function test_untrusted_host_header_is_never_reflected_in_pagination_links(): void
    {
        Product::factory()->count(3)->create();
        $this->actingAsViewer();

        $response = $this->withHeader('Host', 'evil.test')->getJson('/api/v1/products?per_page=1');

        $this->assertStringNotContainsString('evil.test', (string) $response->getContent());
    }

    public function test_application_host_and_its_subdomains_are_accepted(): void
    {
        Product::factory()->count(2)->create();
        $this->actingAsViewer();

        $this->getJson('http://localhost/api/v1/products?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.path', 'http://localhost/api/v1/products');
        $this->getJson('http://api.localhost/api/v1/products')->assertOk();
    }

    public function test_loopback_addresses_are_accepted_outside_production(): void
    {
        $this->actingAsViewer();

        $this->getJson('http://127.0.0.1:8000/api/v1/products')->assertOk();
    }
}
