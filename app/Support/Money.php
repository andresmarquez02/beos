<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    public const string MAX = '9999999999999.99';

    /**
     * Reglas de validación para un importe monetario con 2 decimales como máximo.
     *
     * @return list<string>
     */
    public static function rules(string $min = '0'): array
    {
        return ['numeric', 'decimal:0,2', 'min:'.$min, 'max:'.self::MAX];
    }

    public static function normalize(int|float|string $amount): string
    {
        if (is_string($amount)) {
            return $amount;
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
