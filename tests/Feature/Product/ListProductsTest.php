<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsApiUser;
use Tests\TestCase;

class ListProductsTest extends TestCase
{
    use ActsAsApiUser;
    use RefreshDatabase;

    private const URL = '/api/v1/products';

    public function test_returns_first_page_of_fifteen_ordered_by_newest_first(): void
    {
        $products = Product::factory()->count(20)->create();
        $this->actingAsViewer();

        $response = $this->getJson(self::URL);

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data' => ['*' => [
                    'id', 'name', 'description', 'price', 'currency_id',
                    'currency' => ['id', 'code', 'name', 'symbol', 'exchange_rate'],
                    'tax_cost', 'manufacturing_cost', 'created_at', 'updated_at',
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('data.0.id', $products->last()->id)
            ->assertJsonPath('data.14.id', $products[5]->id);
    }

    public function test_paginates_with_page_and_per_page(): void
    {
        $products = Product::factory()->count(20)->create();
        $this->actingAsViewer();

        $response = $this->getJson(self::URL.'?per_page=5&page=2');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('data.0.id', $products[14]->id);
        $this->assertStringContainsString('per_page=5', $response->json('links.next'));
    }

    public function test_empty_optional_parameters_fall_back_to_defaults(): void
    {
        Product::factory()->count(3)->create();
        $this->actingAsViewer();

        $this->getJson(self::URL.'?per_page=&page=&search=&sort=')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_unknown_parameters_are_ignored(): void
    {
        Product::factory()->count(2)->create();
        $this->actingAsViewer();

        $this->getJson(self::URL.'?foo=bar')->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'per_page above max' => ['per_page=101', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'per_page not an integer' => ['per_page=abc', 'per_page'],
            'page zero' => ['page=0', 'page'],
            'page above max' => ['page=100001', 'page'],
            'search too long' => ['search='.str_repeat('a', 101), 'search'],
            'unknown currency' => ['currency_id=999999', 'currency_id'],
            'currency not an integer' => ['currency_id=abc', 'currency_id'],
            'min_price negative' => ['min_price=-1', 'min_price'],
            'min_price three decimals' => ['min_price=1.234', 'min_price'],
            'max_price not numeric' => ['max_price=abc', 'max_price'],
            'min_price greater than max_price' => ['min_price=50&max_price=10', 'min_price'],
            'sort outside whitelist' => ['sort=password', 'sort'],
            'sort with injection attempt' => ['sort=id;drop table products', 'sort'],
            'sort with mixed sign' => ['sort=--id', 'sort'],
        ];
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_query_returns_422(string $query, string $field): void
    {
        $this->actingAsViewer();

        $this->getJson(self::URL.'?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonValidationErrors($field);
    }

    public function test_search_is_case_insensitive_substring(): void
    {
        Product::factory()->create(['name' => 'Gaming LAPTOP']);
        Product::factory()->create(['name' => 'Lapel mic']);
        Product::factory()->create(['name' => 'Phone']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?search=lap')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Phone']);
    }

    public function test_search_treats_percent_and_underscore_as_literals(): void
    {
        Product::factory()->create(['name' => '100% cotton']);
        Product::factory()->create(['name' => '100 cotton']);
        Product::factory()->create(['name' => 'a_b']);
        Product::factory()->create(['name' => 'axb']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?search='.urlencode('100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '100% cotton');

        $this->getJson(self::URL.'?search=a_b')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'a_b');
    }

    public function test_search_treats_backslash_as_literal(): void
    {
        Product::factory()->create(['name' => 'back\\slash']);
        Product::factory()->create(['name' => 'backslash']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?search='.urlencode('\\'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'back\\slash');
    }

    public function test_filters_by_currency_and_inclusive_price_range(): void
    {
        $usd = Currency::factory()->create();
        $eur = Currency::factory()->create();
        Product::factory()->for($usd)->create(['price' => '9.99']);
        $low = Product::factory()->for($usd)->create(['price' => '10.00']);
        $mid = Product::factory()->for($usd)->create(['price' => '30.00']);
        $high = Product::factory()->for($usd)->create(['price' => '50.00']);
        Product::factory()->for($usd)->create(['price' => '50.01']);
        Product::factory()->for($eur)->create(['price' => '30.00']);
        $this->actingAsViewer();

        $response = $this->getJson(self::URL.'?currency_id='.$usd->id.'&min_price=10&max_price=50&sort=price');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $low->id)
            ->assertJsonPath('data.1.id', $mid->id)
            ->assertJsonPath('data.2.id', $high->id);
    }

    public function test_min_price_equal_to_max_price_is_accepted(): void
    {
        Product::factory()->create(['price' => '25.00']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?min_price=25&max_price=25')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sorts_by_whitelisted_fields(): void
    {
        $b = Product::factory()->create(['name' => 'B', 'price' => '20.00']);
        $a = Product::factory()->create(['name' => 'A', 'price' => '30.00']);
        $c = Product::factory()->create(['name' => 'C', 'price' => '10.00']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?sort=name')->assertOk()
            ->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.2.id', $c->id);
        $this->getJson(self::URL.'?sort=-price')->assertOk()
            ->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.2.id', $c->id);
        $this->getJson(self::URL.'?sort=id')->assertOk()
            ->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.2.id', $c->id);
        $this->getJson(self::URL.'?sort=created_at')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_equal_sort_values_are_ordered_deterministically_by_id_desc(): void
    {
        $first = Product::factory()->create(['price' => '10.00']);
        $second = Product::factory()->create(['price' => '10.00']);
        $this->actingAsViewer();

        $this->getJson(self::URL.'?sort=price')->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_soft_deleted_products_are_excluded(): void
    {
        $kept = Product::factory()->create();
        Product::factory()->create()->delete();
        $this->actingAsViewer();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_number_of_queries_does_not_depend_on_page_size(): void
    {
        $this->actingAsViewer();

        Product::factory()->count(3)->create();
        $small = $this->countQueries(fn () => $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data'));

        Product::factory()->count(27)->create();
        $large = $this->countQueries(fn () => $this->getJson(self::URL.'?per_page=30')->assertOk()->assertJsonCount(30, 'data'));

        $this->assertSame($small, $large);
        $this->assertSame(3, $large);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_token_without_read_ability_is_forbidden(): void
    {
        $this->actingWithAbilities([], UserRole::Admin);

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
