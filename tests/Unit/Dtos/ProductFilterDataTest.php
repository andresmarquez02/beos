<?php

declare(strict_types=1);

namespace Tests\Unit\Dtos;

use App\DTOs\ProductFilterData;
use App\Enums\ProductSortField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductFilterDataTest extends TestCase
{
    public function test_defaults(): void
    {
        foreach ([new ProductFilterData, ProductFilterData::fromValidated([])] as $filters) {
            $this->assertNull($filters->search);
            $this->assertNull($filters->currencyId);
            $this->assertNull($filters->minPrice);
            $this->assertNull($filters->maxPrice);
            $this->assertSame(ProductSortField::Id, $filters->sort);
            $this->assertTrue($filters->descending);
            $this->assertSame(15, $filters->perPage);
            $this->assertSame(1, $filters->page);
        }
    }

    public function test_maps_validated_query(): void
    {
        $filters = ProductFilterData::fromValidated([
            'search' => 'lap', 'currency_id' => '2', 'min_price' => '10', 'max_price' => '50.5',
            'sort' => 'name', 'per_page' => '5', 'page' => '2',
        ]);

        $this->assertSame('lap', $filters->search);
        $this->assertSame(2, $filters->currencyId);
        $this->assertSame('10', $filters->minPrice);
        $this->assertSame('50.5', $filters->maxPrice);
        $this->assertSame(ProductSortField::Name, $filters->sort);
        $this->assertFalse($filters->descending);
        $this->assertSame(5, $filters->perPage);
        $this->assertSame(2, $filters->page);
    }

    public function test_null_values_from_empty_query_parameters_use_defaults(): void
    {
        $filters = ProductFilterData::fromValidated([
            'search' => null, 'currency_id' => null, 'min_price' => null, 'max_price' => null,
            'sort' => null, 'per_page' => null, 'page' => null,
        ]);

        $this->assertEquals(new ProductFilterData, $filters);
    }

    public function test_zero_min_price_is_preserved(): void
    {
        $this->assertSame('0', ProductFilterData::fromValidated(['min_price' => '0'])->minPrice);
    }

    /**
     * @return array<string, array{string, ProductSortField, bool}>
     */
    public static function sortProvider(): array
    {
        return [
            'id asc' => ['id', ProductSortField::Id, false],
            'id desc' => ['-id', ProductSortField::Id, true],
            'name asc' => ['name', ProductSortField::Name, false],
            'price desc' => ['-price', ProductSortField::Price, true],
            'created_at asc' => ['created_at', ProductSortField::CreatedAt, false],
            'created_at desc' => ['-created_at', ProductSortField::CreatedAt, true],
        ];
    }

    #[DataProvider('sortProvider')]
    public function test_parses_sort(string $sort, ProductSortField $field, bool $descending): void
    {
        $filters = ProductFilterData::fromValidated(['sort' => $sort]);

        $this->assertSame($field, $filters->sort);
        $this->assertSame($descending, $filters->descending);
    }

    public function test_allowed_sort_values_cover_every_field_in_both_directions(): void
    {
        $this->assertSame(
            ['id', '-id', 'name', '-name', 'price', '-price', 'created_at', '-created_at'],
            ProductSortField::allowedValues(),
        );
    }
}
