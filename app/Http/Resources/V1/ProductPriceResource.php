<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductPrice
 */
class ProductPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example 7 */
            'id' => $this->id,
            /** @example 21 */
            'product_id' => $this->product_id,
            /** @example 2 */
            'currency_id' => $this->currency_id,
            'currency' => CurrencyResource::make($this->whenLoaded('currency')),
            /** @example 1199.00 */
            'price' => $this->price,
        ];
    }
}
