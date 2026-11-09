<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const EXPECTED = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ];

    public function test_success_responses_carry_security_headers(): void
    {
        $product = Product::factory()->create();
        $this->actingAsViewer();

        $response = $this->getJson('/api/v1/products/'.$product->id)->assertOk();

        foreach (self::EXPECTED as $name => $value) {
            $this->assertSame($value, $response->headers->get($name), $name);
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_error_responses_carry_security_headers(): void
    {
        foreach ([
            $this->getJson('/api/v1/products'),
            $this->getJson('/api/v1/nope'),
            $this->postJson('/api/v1/products', []),
        ] as $response) {
            foreach (self::EXPECTED as $name => $value) {
                $this->assertSame($value, $response->headers->get($name), $name);
            }
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->actingAsViewer();

        $this->getJson('https://localhost/api/v1/products')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_documentation_ui_is_excluded_from_api_security_headers(): void
    {
        $response = $this->get('/docs/api')->assertOk();

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotSame('DENY', $response->headers->get('X-Frame-Options'));
    }
}
