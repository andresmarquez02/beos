<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Support\Money;

final readonly class ProductData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $price,
        public int $currencyId,
        public string $taxCost,
        public string $manufacturingCost,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            description: isset($validated['description']) ? (string) $validated['description'] : null,
            price: Money::normalize($validated['price']),
            currencyId: (int) $validated['currency_id'],
            taxCost: Money::normalize($validated['tax_cost']),
            manufacturingCost: Money::normalize($validated['manufacturing_cost']),
        );
    }
}
