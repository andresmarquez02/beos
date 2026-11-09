<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function invalidProductColumnsProvider(): array
    {
        return [
            'price zero' => ['products_price_positive', ['price' => '0.00']],
            'price negative' => ['products_price_positive', ['price' => '-1.00']],
            'tax cost negative' => ['products_tax_cost_non_negative', ['tax_cost' => '-0.01']],
            'manufacturing cost negative' => ['products_manufacturing_cost_non_negative', ['manufacturing_cost' => '-0.01']],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidProductColumnsProvider')]
    public function test_products_check_constraints(string $constraint, array $override): void
    {
        $currency = Currency::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($constraint);

        DB::table('products')->insert(array_merge([
            'name' => 'Bad',
            'price' => '1.00',
            'currency_id' => $currency->id,
            'tax_cost' => '0.00',
            'manufacturing_cost' => '0.00',
        ], $override));
    }

    public function test_product_price_must_be_positive(): void
    {
        $product = Product::factory()->create();
        $currency = Currency::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('product_prices_price_positive');

        DB::table('product_prices')->insert(['product_id' => $product->id, 'currency_id' => $currency->id, 'price' => '0.00']);
    }

    public function test_product_price_is_unique_per_product_and_currency(): void
    {
        $price = ProductPrice::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('product_prices')->insert([
            'product_id' => $price->product_id,
            'currency_id' => $price->currency_id,
            'price' => '5.00',
        ]);
    }

    public function test_exchange_rate_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('currencies_exchange_rate_positive');

        DB::table('currencies')->insert(['code' => 'ABC', 'name' => 'Bad', 'symbol' => '$', 'exchange_rate' => '0']);
    }

    public function test_currency_code_must_be_three_uppercase_letters(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('currencies_code_format');

        DB::table('currencies')->insert(['code' => 'abc', 'name' => 'Bad', 'symbol' => '$', 'exchange_rate' => '1']);
    }

    public function test_currency_code_and_name_are_unique(): void
    {
        Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('currencies')->insert(['code' => 'USD', 'name' => 'Other', 'symbol' => '$', 'exchange_rate' => '1']);
    }

    public function test_currency_in_use_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('currencies')->where('id', $product->currency_id)->delete();
    }

    public function test_product_with_prices_cannot_be_hard_deleted(): void
    {
        $price = ProductPrice::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('products')->where('id', $price->product_id)->delete();
    }

    public function test_user_role_must_be_valid(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_role_valid');

        DB::table('users')->insert(['name' => 'X', 'email' => 'x@example.com', 'password' => 'x', 'role' => 'root']);
    }
}
