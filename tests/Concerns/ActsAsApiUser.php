<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait ActsAsApiUser
{
    protected function actingAsAdmin(): User
    {
        return $this->actingWithAbilities(['products:read', 'products:write'], UserRole::Admin);
    }

    protected function actingAsViewer(): User
    {
        return $this->actingWithAbilities(['products:read'], UserRole::Viewer);
    }

    /**
     * @param  list<string>  $abilities
     */
    protected function actingWithAbilities(array $abilities, UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->state(['role' => $role])->create();

        Sanctum::actingAs($user, $abilities);

        return $user;
    }
}
