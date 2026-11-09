<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tokenCan(TokenAbility::ProductsRead->value);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->tokenCan(TokenAbility::ProductsWrite->value);
    }
}
