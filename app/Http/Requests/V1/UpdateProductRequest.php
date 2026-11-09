<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\Product;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && (bool) $this->user()?->can('update', $product);
    }
}
