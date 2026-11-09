<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ProductSortField;
use App\Support\Money;

final readonly class ProductFilterData
{
    public const int DEFAULT_PER_PAGE = 15;

    public function __construct(
        public ?string $search = null,
        public ?int $currencyId = null,
        public ?string $minPrice = null,
        public ?string $maxPrice = null,
        public ProductSortField $sort = ProductSortField::Id,
        public bool $descending = true,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public int $page = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $sort = (string) ($validated['sort'] ?? '-id');

        return new self(
            search: isset($validated['search']) ? (string) $validated['search'] : null,
            currencyId: isset($validated['currency_id']) ? (int) $validated['currency_id'] : null,
            minPrice: isset($validated['min_price']) ? Money::normalize($validated['min_price']) : null,
            maxPrice: isset($validated['max_price']) ? Money::normalize($validated['max_price']) : null,
            sort: ProductSortField::from(ltrim($sort, '-')),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
