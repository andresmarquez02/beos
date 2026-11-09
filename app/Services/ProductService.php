<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ProductData;
use App\DTOs\ProductFilterData;
use App\Models\Product;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductPriceRepositoryInterface $prices,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function list(ProductFilterData $filters): LengthAwarePaginator
    {
        return $this->products->paginate($filters);
    }

    public function create(ProductData $data): Product
    {
        return $this->products->create($data);
    }

    public function update(Product $product, ProductData $data): Product
    {
        if ($data->currencyId !== $product->currency_id
            && $this->prices->existsForProductAndCurrency($product->id, $data->currencyId)) {
            throw ValidationException::withMessages([
                'currency_id' => ['The selected currency already has a registered price for this product.'],
            ]);
        }

        return $this->products->update($product, $data);
    }

    public function delete(Product $product): void
    {
        $this->products->delete($product);
    }
}
