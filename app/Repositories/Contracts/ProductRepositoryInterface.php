<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\ProductData;
use App\DTOs\ProductFilterData;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilterData $filters): LengthAwarePaginator;

    public function findOrFail(int $id): Product;

    public function create(ProductData $data): Product;

    public function update(Product $product, ProductData $data): Product;

    public function delete(Product $product): void;
}
