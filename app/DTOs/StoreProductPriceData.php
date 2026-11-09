<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Support\Money;

final readonly class StoreProductPriceData
{
    public function __construct(
        public int $currencyId,
        public string $price,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            currencyId: (int) $validated['currency_id'],
            price: Money::normalize($validated['price']),
        );
    }
}
