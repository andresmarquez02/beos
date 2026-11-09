<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (UserRole::cases() as $role) {
            $email = $role->value.'@example.com';

            $user = User::query()->where('email', $email)->first()
                ?? User::factory()->state(['role' => $role])->create([
                    'name' => ucfirst($role->value),
                    'email' => $email,
                ]);

            $secret = config('api.demo_tokens.'.$role->value);

            if (is_string($secret) && $secret !== '') {
                $this->seedDemoToken($user, $role, $secret);
            }
        }
    }

    /**
     * Un token sin caducidad (`expires_at` nulo) por usuario demo, localizado por nombre: al re-sembrar
     * conserva su id y reemplaza el secreto si el configurado cambió.
     */
    private function seedDemoToken(User $user, UserRole $role, string $secret): void
    {
        $token = $user->tokens()->firstOrNew(['name' => 'demo-'.$role->value]);
        $token->fill(['token' => hash('sha256', $secret), 'abilities' => $role->abilities(), 'expires_at' => null])->save();

        $this->command?->info("Demo {$role->value} token: {$token->getKey()}|{$secret}");
    }
}
