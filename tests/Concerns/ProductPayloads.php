<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Currency;

trait ProductPayloads
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validProductPayload(Currency $currency, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laptop Pro 14',
            'description' => 'A laptop',
            'price' => '1299.90',
            'currency_id' => $currency->id,
            'tax_cost' => '249.00',
            'manufacturing_cost' => '800.00',
        ], $overrides);
    }

    /**
     * Cada caso: [valores a sobrescribir, claves a quitar, campo esperado en `errors`].
     *
     * @return array<string, array{array<string, mixed>, list<string>, string}>
     */
    public static function invalidProductPayloadProvider(): array
    {
        $tooBig = '10000000000000.00';

        return [
            'name missing' => [[], ['name'], 'name'],
            'name too short' => [['name' => 'a'], [], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], [], 'name'],
            'name not a string' => [['name' => ['x']], [], 'name'],
            'description too long' => [['description' => str_repeat('a', 2001)], [], 'description'],
            'price missing' => [[], ['price'], 'price'],
            'price zero' => [['price' => 0], [], 'price'],
            'price zero string' => [['price' => '0.00'], [], 'price'],
            'price negative' => [['price' => '-1.00'], [], 'price'],
            'price three decimals' => [['price' => '19.999'], [], 'price'],
            'price not numeric' => [['price' => 'abc'], [], 'price'],
            'price above max' => [['price' => $tooBig], [], 'price'],
            'currency missing' => [[], ['currency_id'], 'currency_id'],
            'currency unknown' => [['currency_id' => 999999], [], 'currency_id'],
            'currency not an integer' => [['currency_id' => 'abc'], [], 'currency_id'],
            'currency decimal' => [['currency_id' => 1.5], [], 'currency_id'],
            'currency zero' => [['currency_id' => 0], [], 'currency_id'],
            'currency beyond bigint' => [['currency_id' => '99999999999999999999'], [], 'currency_id'],
            'tax cost missing' => [[], ['tax_cost'], 'tax_cost'],
            'tax cost negative' => [['tax_cost' => '-0.01'], [], 'tax_cost'],
            'tax cost three decimals' => [['tax_cost' => '1.234'], [], 'tax_cost'],
            'tax cost above max' => [['tax_cost' => $tooBig], [], 'tax_cost'],
            'manufacturing cost missing' => [[], ['manufacturing_cost'], 'manufacturing_cost'],
            'manufacturing cost negative' => [['manufacturing_cost' => -5], [], 'manufacturing_cost'],
            'manufacturing cost not numeric' => [['manufacturing_cost' => 'x'], [], 'manufacturing_cost'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $remove
     * @return array<string, mixed>
     */
    protected function invalidProductPayload(Currency $currency, array $overrides, array $remove): array
    {
        $payload = $this->validProductPayload($currency, $overrides);

        foreach ($remove as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }
}
