<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\StoreProductPriceData;
use App\Exceptions\ConflictException;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class ProductPriceService
{
    private const string DUPLICATE_MESSAGE = 'A price already exists for this product and currency.';

    public function __construct(
        private readonly ProductPriceRepositoryInterface $prices,
    ) {}

    /**
     * @return Collection<int, ProductPrice>
     */
    public function list(Product $product): Collection
    {
        return $this->prices->listByProduct($product);
    }

    public function create(Product $product, StoreProductPriceData $data): ProductPrice
    {
        if ($data->currencyId === $product->currency_id) {
            throw ValidationException::withMessages([
                'currency_id' => ['The currency must differ from the product base currency.'],
            ]);
        }

        if ($this->prices->existsForProductAndCurrency($product->id, $data->currencyId)) {
            throw new ConflictException(self::DUPLICATE_MESSAGE);
        }

        try {
            return $this->prices->create($product, $data);
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictException(self::DUPLICATE_MESSAGE, previous: $e);
        }
    }
}
