<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\ProductData;
use App\DTOs\ProductFilterData;
use App\Enums\ProductSortField;
use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    public function paginate(ProductFilterData $filters): LengthAwarePaginator
    {
        $query = Product::query()->with('currency');

        if ($filters->search !== null) {
            $query->where('name', 'ILIKE', '%'.addcslashes($filters->search, '\\%_').'%');
        }

        if ($filters->currencyId !== null) {
            $query->where('currency_id', $filters->currencyId);
        }

        if ($filters->minPrice !== null) {
            $query->where('price', '>=', $filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $query->where('price', '<=', $filters->maxPrice);
        }

        $query->orderBy($filters->sort->value, $filters->descending ? 'desc' : 'asc');

        if ($filters->sort !== ProductSortField::Id) {
            $query->orderBy('id', 'desc');
        }

        return $query
            ->paginate($filters->perPage, ['*'], 'page', $filters->page)
            ->withQueryString();
    }

    public function findOrFail(int $id): Product
    {
        return Product::query()->with('currency')->findOrFail($id);
    }

    public function create(ProductData $data): Product
    {
        return Product::query()->create($this->attributes($data))->load('currency');
    }

    public function update(Product $product, ProductData $data): Product
    {
        $product->fill($this->attributes($data))->save();

        return $product->load('currency');
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductData $data): array
    {
        return [
            'name' => $data->name,
            'description' => $data->description,
            'price' => $data->price,
            'currency_id' => $data->currencyId,
            'tax_cost' => $data->taxCost,
            'manufacturing_cost' => $data->manufacturingCost,
        ];
    }
}
