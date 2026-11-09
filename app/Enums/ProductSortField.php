<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductSortField: string
{
    case Id = 'id';
    case Name = 'name';
    case Price = 'price';
    case CreatedAt = 'created_at';

    /**
     * @return list<string>
     */
    public static function allowedValues(): array
    {
        $values = [];

        foreach (self::cases() as $field) {
            $values[] = $field->value;
            $values[] = '-'.$field->value;
        }

        return $values;
    }
}
