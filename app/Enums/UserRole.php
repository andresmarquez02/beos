<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Admin => [TokenAbility::ProductsRead->value, TokenAbility::ProductsWrite->value],
            self::Viewer => [TokenAbility::ProductsRead->value],
        };
    }
}
