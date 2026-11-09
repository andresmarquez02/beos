<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Currency
 */
class CurrencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @example 2 */
            'id' => $this->id,
            /** @example EUR */
            'code' => $this->code,
            /** @example Euro */
            'name' => $this->name,
            /** @example € */
            'symbol' => $this->symbol,
            /** @example 0.920000 */
            'exchange_rate' => $this->exchange_rate,
        ];
    }
}
