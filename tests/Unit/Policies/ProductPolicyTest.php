<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    private const READ = 'products:read';

    private const WRITE = 'products:write';

    /**
     * Cada caso: [rol, abilities del token (null = sin token), lectura permitida, escritura permitida].
     *
     * @return array<string, array{UserRole, list<string>|null, bool, bool}>
     */
    public static function matrixProvider(): array
    {
        return [
            'admin with full token' => [UserRole::Admin, [self::READ, self::WRITE], true, true],
            'admin with read-only token' => [UserRole::Admin, [self::READ], true, false],
            'admin with write-only token' => [UserRole::Admin, [self::WRITE], false, true],
            'admin with empty token' => [UserRole::Admin, [], false, false],
            'admin without token' => [UserRole::Admin, null, false, false],
            'viewer with read token' => [UserRole::Viewer, [self::READ], true, false],
            'viewer with write ability in token' => [UserRole::Viewer, [self::READ, self::WRITE], true, false],
            'viewer with empty token' => [UserRole::Viewer, [], false, false],
            'viewer without token' => [UserRole::Viewer, null, false, false],
        ];
    }

    /**
     * @param  list<string>|null  $abilities
     */
    #[DataProvider('matrixProvider')]
    public function test_role_and_token_ability_matrix(UserRole $role, ?array $abilities, bool $canRead, bool $canWrite): void
    {
        $user = $this->user($role, $abilities);
        $product = new Product;
        $policy = new ProductPolicy;

        $this->assertSame($canRead, $policy->viewAny($user));
        $this->assertSame($canRead, $policy->view($user, $product));
        $this->assertSame($canWrite, $policy->create($user));
        $this->assertSame($canWrite, $policy->update($user, $product));
        $this->assertSame($canWrite, $policy->delete($user, $product));
    }

    public function test_gate_resolves_the_policy_by_convention(): void
    {
        $user = $this->user(UserRole::Viewer, [self::READ]);

        $this->assertTrue($user->can('viewAny', Product::class));
        $this->assertFalse($user->can('create', Product::class));
    }

    /**
     * @param  list<string>|null  $abilities
     */
    private function user(UserRole $role, ?array $abilities): User
    {
        $user = User::factory()->make(['role' => $role]);

        if ($abilities !== null) {
            $user->withAccessToken(new PersonalAccessToken(['abilities' => $abilities]));
        }

        return $user;
    }
}
