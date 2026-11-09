<?php

declare(strict_types=1);

use App\Http\Controllers\V1\ProductController;
use App\Http\Controllers\V1\ProductPriceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::get('products/{product}/prices', [ProductPriceController::class, 'index'])->name('products.prices.index');
    Route::post('products/{product}/prices', [ProductPriceController::class, 'store'])->name('products.prices.store');
});
