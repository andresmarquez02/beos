<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api:token {email : Email of the user} {--name=cli : Name of the token}')]
#[Description('Issue an API token with the abilities of the user role and print it')]
class IssueApiToken extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->components->error('No user found with that email.');

            return self::FAILURE;
        }

        $token = $user->createToken(
            (string) $this->option('name'),
            $user->role->abilities(),
            now()->addMinutes((int) config('api.token_ttl_minutes'))->startOfSecond(),
        );

        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
