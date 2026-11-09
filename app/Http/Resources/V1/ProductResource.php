<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example 21 */
            'id' => $this->id,
            /** @example Laptop Pro 14 */
            'name' => $this->name,
            'description' => $this->description,
            /** @example 1299.90 */
            'price' => $this->price,
            /** @example 1 */
            'currency_id' => $this->currency_id,
            'currency' => CurrencyResource::make($this->whenLoaded('currency')),
            /** @example 249.00 */
            'tax_cost' => $this->tax_cost,
            /** @example 800.00 */
            'manufacturing_cost' => $this->manufacturing_cost,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
