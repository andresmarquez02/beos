<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{int|float|string, string}>
     */
    public static function amountProvider(): array
    {
        return [
            'float' => [19.99, '19.99'],
            'float with one decimal' => [1299.9, '1299.90'],
            'integer' => [20, '20.00'],
            'zero' => [0, '0.00'],
            'string is left untouched' => ['19.9', '19.9'],
            'string with two decimals' => ['19.99', '19.99'],
            'max amount as float does not become scientific notation' => [9999999999999.99, '9999999999999.99'],
            'max amount as string' => ['9999999999999.99', '9999999999999.99'],
        ];
    }

    #[DataProvider('amountProvider')]
    public function test_normalize(int|float|string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::normalize($amount));
    }
}
