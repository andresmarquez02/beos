<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Support\ErrorResponsesDocumentation;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    private const string ID_PATTERN = '[1-9][0-9]{0,17}';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->registerTrustedHosts();
        $this->registerRateLimiters();
        $this->registerRouteBindings();

        Gate::define('viewApiDocs', fn ($user = null): bool => (bool) config('api.docs_enabled'));
        Scramble::afterOpenApiGenerated(new ErrorResponsesDocumentation);
    }

    private function registerTrustedHosts(): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host)) {
            return;
        }

        $patterns = ['^(.+\.)?'.preg_quote($host).'$'];

        if ($this->app->environment('local', 'testing')) {
            array_push($patterns, '^127\.0\.0\.1$', '^\[::1\]$');
        }

        Request::setTrustedHosts($patterns);
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute((int) config('api.rate_limits.api'))
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });
    }

    private function registerRouteBindings(): void
    {
        Route::pattern('product', self::ID_PATTERN);

        Route::bind('product', fn (string $id) => $this->app->make(ProductRepositoryInterface::class)
            ->findOrFail((int) $id));
    }
}
