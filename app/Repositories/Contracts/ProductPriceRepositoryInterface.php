<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\StoreProductPriceData;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Collection;

interface ProductPriceRepositoryInterface
{
    /**
     * @return Collection<int, ProductPrice>
     */
    public function listByProduct(Product $product): Collection;

    public function existsForProductAndCurrency(int $productId, int $currencyId): bool;

    public function create(Product $product, StoreProductPriceData $data): ProductPrice;
}
