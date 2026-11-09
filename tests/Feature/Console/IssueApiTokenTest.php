<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class IssueApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_a_working_admin_token_with_read_and_write_abilities(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $plain = $this->issue('admin@example.com');

        $record = PersonalAccessToken::query()->sole();
        $this->assertSame($user->id, $record->tokenable_id);
        $this->assertSame(['products:read', 'products:write'], $record->abilities);
        $this->assertSame('cli', $record->name);
        $this->assertEqualsWithDelta(now()->addMinutes(480)->timestamp, $record->expires_at->timestamp, 5);
        $this->assertSame($record->getKey().'|', substr($plain, 0, strlen((string) $record->getKey()) + 1));

        $this->withToken($plain)->getJson('/api/v1/products')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/products', [])->assertUnprocessable();
    }

    public function test_viewer_token_is_read_only(): void
    {
        User::factory()->viewer()->create(['email' => 'viewer@example.com']);

        $plain = $this->issue('viewer@example.com');

        $this->assertSame(['products:read'], PersonalAccessToken::query()->sole()->abilities);
        $this->withToken($plain)->getJson('/api/v1/products')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/products', [])->assertForbidden();
    }

    public function test_name_option_and_ttl_configuration_are_honoured(): void
    {
        config(['api.token_ttl_minutes' => 30]);
        User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->issue('admin@example.com', ['--name' => 'postman']);

        $record = PersonalAccessToken::query()->sole();
        $this->assertSame('postman', $record->name);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $record->expires_at->timestamp, 5);
    }

    public function test_email_lookup_is_case_insensitive_and_each_call_issues_a_new_token(): void
    {
        User::factory()->admin()->create(['email' => 'admin@example.com']);

        $first = $this->issue('ADMIN@Example.com');
        $second = $this->issue('admin@example.com');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_unknown_email_fails_without_issuing_a_token(): void
    {
        $this->artisan('api:token', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user found with that email.')
            ->assertFailed();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function issue(string $email, array $options = []): string
    {
        $this->assertSame(0, Artisan::call('api:token', ['email' => $email, ...$options]));

        return trim(Artisan::output());
    }
}
