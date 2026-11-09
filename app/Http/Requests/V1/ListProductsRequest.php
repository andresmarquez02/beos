<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Enums\ProductSortField;
use App\Models\Product;
use App\Rules\SafeString;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Product::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * Número de página.
             *
             * @example 1
             */
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100000'],
            /**
             * Elementos por página (1-100, por defecto 15).
             *
             * @example 15
             */
            'per_page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
            /**
             * Texto contenido en el nombre, sin distinguir mayúsculas.
             *
             * @example lap
             */
            'search' => ['bail', 'nullable', 'string', new SafeString, 'max:100'],
            /**
             * Filtra por divisa base.
             *
             * @example 1
             */
            'currency_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:currencies,id'],
            /**
             * Precio base mínimo (inclusivo).
             *
             * @example 10
             */
            'min_price' => [
                'bail', 'nullable', ...Money::rules(),
                Rule::when($this->filled('max_price'), ['lte:max_price']),
            ],
            /**
             * Precio base máximo (inclusivo).
             *
             * @example 50
             */
            'max_price' => ['bail', 'nullable', ...Money::rules()],
            /**
             * Campo de orden; prefijo `-` para descendente. Valores: `id`, `name`, `price`, `created_at`. Por defecto `-id`.
             *
             * @example -price
             */
            'sort' => ['bail', 'nullable', 'string', Rule::in(ProductSortField::allowedValues())],
        ];
    }
}
