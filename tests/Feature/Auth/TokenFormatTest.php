<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function invalidBearerProvider(): array
    {
        return [
            'id beyond bigint (20 digits)' => ['99999999999999999999|x'],
            'id just above bigint max' => ['9223372036854775808|x'],
            'id with 19 digits' => ['1000000000000000000|x'],
            'zero id' => ['0|x'],
            'negative id' => ['-1|x'],
            'leading zero id' => ['01|x'],
            'non numeric id' => ['abc|x'],
            'decimal id' => ['1.5|x'],
            'empty id' => ['|x'],
            'no id separator' => ['plainsecret'],
            'invalid utf-8' => ["\xFF\xFE|x"],
            'valid shape but unknown id' => ['123456789012345678|x'],
        ];
    }

    #[DataProvider('invalidBearerProvider')]
    public function test_malformed_bearer_tokens_return_401_not_500(string $token): void
    {
        $this->withToken($token)->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_custom_token_model_is_registered_in_sanctum(): void
    {
        $this->assertSame(PersonalAccessToken::class, Sanctum::$personalAccessTokenModel);
    }

    public function test_valid_token_is_found_and_tampered_secret_is_rejected(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('api', ['products:read'], now()->addHour())->plainTextToken;

        $this->assertInstanceOf(PersonalAccessToken::class, PersonalAccessToken::findToken($plain));
        $this->assertNull(PersonalAccessToken::findToken($plain.'x'));
        $this->assertNull(PersonalAccessToken::findToken('99999999999999999999|x'));
    }
}
