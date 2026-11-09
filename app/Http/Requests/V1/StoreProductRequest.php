<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\Product;
use App\Rules\SafeString;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Product::class);
    }

    /**
     * @return array<string, list<string|SafeString>>
     */
    public function rules(): array
    {
        return [
            /**
             * Nombre del producto (2-255 caracteres).
             *
             * @example Laptop Pro 14
             */
            'name' => ['bail', 'required', 'string', new SafeString, 'min:2', 'max:255'],
            /**
             * Descripción opcional (máximo 2000 caracteres).
             *
             * @example 14-inch laptop
             */
            'description' => ['bail', 'nullable', 'string', new SafeString, 'max:2000'],
            /**
             * Precio base, máximo 2 decimales. Se recomienda enviarlo como string.
             *
             * @example 1299.90
             */
            'price' => ['bail', 'required', ...Money::rules('0.01')],
            /**
             * Id de la divisa base. En una base recién sembrada: USD 1, EUR 2, GBP 3, COP 4, MXN 5.
             *
             * @example 1
             */
            'currency_id' => ['bail', 'required', 'integer', 'min:1', 'exists:currencies,id'],
            /**
             * Costo de impuestos (enviar `0` explícito si no aplica).
             *
             * @example 249.00
             */
            'tax_cost' => ['bail', 'required', ...Money::rules()],
            /**
             * Costo de fabricación (enviar `0` explícito si no aplica).
             *
             * @example 800.00
             */
            'manufacturing_cost' => ['bail', 'required', ...Money::rules()],
        ];
    }
}
