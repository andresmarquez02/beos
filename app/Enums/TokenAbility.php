<?php

declare(strict_types=1);

namespace App\Enums;

enum TokenAbility: string
{
    case ProductsRead = 'products:read';
    case ProductsWrite = 'products:write';
}
