<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\StoreProductPriceData;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentProductPriceRepository implements ProductPriceRepositoryInterface
{
    public function listByProduct(Product $product): Collection
    {
        return ProductPrice::query()
            ->join('currencies', 'currencies.id', '=', 'product_prices.currency_id')
            ->where('product_prices.product_id', $product->id)
            ->orderBy('currencies.code')
            ->select('product_prices.*')
            ->with('currency')
            ->get();
    }

    public function existsForProductAndCurrency(int $productId, int $currencyId): bool
    {
        return ProductPrice::query()
            ->where('product_id', $productId)
            ->where('currency_id', $currencyId)
            ->exists();
    }

    public function create(Product $product, StoreProductPriceData $data): ProductPrice
    {
        return DB::transaction(
            fn (): ProductPrice => $product->prices()
                ->create(['currency_id' => $data->currencyId, 'price' => $data->price])
                ->load('currency'),
        );
    }
}
