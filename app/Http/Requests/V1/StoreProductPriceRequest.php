<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && (bool) $this->user()?->can('update', $product);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Id de la divisa del precio. Debe ser distinta de la divisa base del producto.
             *
             * @example 2
             */
            'currency_id' => ['bail', 'required', 'integer', 'min:1', 'exists:currencies,id'],
            /**
             * Precio en esa divisa, máximo 2 decimales.
             *
             * @example 1199.00
             */
            'price' => ['bail', 'required', ...Money::rules('0.01')],
        ];
    }
}
