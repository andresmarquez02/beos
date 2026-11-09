<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\PersonalAccessToken;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_SECRET = 'admin-demo-secret-0123456789abcdef0123456789abcdef';

    private const VIEWER_SECRET = 'viewer-demo-secret-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.demo_tokens' => ['admin' => self::ADMIN_SECRET, 'viewer' => self::VIEWER_SECRET]]);
    }

    public function test_currencies_are_seeded_in_a_fixed_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['USD', 'EUR', 'GBP', 'COP', 'MXN'],
            Currency::query()->orderBy('id')->pluck('code')->all(),
        );
        $this->assertSame('1.000000', Currency::query()->where('code', 'USD')->value('exchange_rate'));
    }

    public function test_demo_users_and_tokens_are_created_with_the_abilities_of_their_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(UserRole::Admin, User::query()->where('email', 'admin@example.com')->sole()->role);
        $this->assertSame(UserRole::Viewer, User::query()->where('email', 'viewer@example.com')->sole()->role);

        $admin = PersonalAccessToken::findToken($this->adminToken());
        $viewer = PersonalAccessToken::findToken($this->viewerToken());

        $this->assertNotNull($admin);
        $this->assertNotNull($viewer);
        $this->assertSame(['products:read', 'products:write'], $admin->abilities);
        $this->assertSame(['products:read'], $viewer->abilities);
        $this->assertNull($admin->expires_at);
        $this->assertNull($viewer->expires_at);
        $this->assertSame(1, $viewer->getKey() - $admin->getKey());
    }

    public function test_demo_tokens_authenticate_with_the_documented_values(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->withToken($this->adminToken())->postJson('/api/v1/products', [])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->viewerToken())->getJson('/api/v1/products')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->viewerToken())->postJson('/api/v1/products', [])->assertForbidden();
    }

    public function test_demo_tokens_never_expire(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->travel(5)->years();

        $this->withToken($this->adminToken())->getJson('/api/v1/products')->assertOk();
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->adminToken();
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('currencies', 5);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseCount('products', 4);
        $this->assertDatabaseCount('product_prices', 6);
        $this->assertSame($token, $this->adminToken());
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_changing_the_secret_replaces_the_token_value_and_keeps_its_id(): void
    {
        $this->seed(DatabaseSeeder::class);
        $old = $this->adminToken();

        config(['api.demo_tokens.admin' => 'another-secret']);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertNull(PersonalAccessToken::findToken($old));
        $this->assertNotNull(PersonalAccessToken::findToken(explode('|', $old)[0].'|another-secret'));
    }

    public function test_no_tokens_are_created_when_no_secrets_are_configured(): void
    {
        config(['api.demo_tokens' => ['admin' => null, 'viewer' => '']]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_users_and_tokens_are_not_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(UserSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_demo_products_are_seeded_with_prices_in_other_currencies(): void
    {
        $this->seed(DatabaseSeeder::class);

        $keyboard = Product::query()->where('name', 'Mechanical Keyboard')->sole();

        $this->assertSame('89.00', $keyboard->price);
        $this->assertSame('USD', $keyboard->currency->code);
        $this->assertSame(['EUR', 'GBP', 'MXN'], $keyboard->prices()->with('currency')->get()->pluck('currency.code')->sort()->values()->all());
        // Ningún precio sembrado puede usar la divisa base de su producto (RN-04).
        ProductPrice::query()->with('product')->get()
            ->each(fn (ProductPrice $price) => $this->assertNotSame($price->product->currency_id, $price->currency_id));
    }

    public function test_demo_products_are_not_seeded_in_production(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->app['env'] = 'production';

        $this->app->make(ProductSeeder::class)->run();

        $this->assertDatabaseCount('products', 0);
    }

    private function adminToken(): string
    {
        return PersonalAccessToken::query()->where('name', 'demo-admin')->sole()->getKey().'|'.self::ADMIN_SECRET;
    }

    private function viewerToken(): string
    {
        return PersonalAccessToken::query()->where('name', 'demo-viewer')->sole()->getKey().'|'.self::VIEWER_SECRET;
    }
}
