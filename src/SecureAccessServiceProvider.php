<?php

namespace SecureAccess;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Authorization\AccessScope;
use SecureAccess\Console\SeedCampAccessCommand;
use SecureAccess\Console\SeedCampDemoUsersCommand;
use SecureAccess\Console\UnlockAccountCommand;
use SecureAccess\Contracts\SmsSender;
use SecureAccess\Http\Middleware\EnsureAccessPermission;
use SecureAccess\Http\Middleware\EnsureMfaVerified;
use SecureAccess\Sms\LogSmsSender;

class SecureAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/secure-access.php', 'secure-access');
        $this->mergeConfigFrom(__DIR__.'/../config/coppa.php', 'coppa');
        $this->mergeConfigFrom(__DIR__.'/../config/secure-access-acl.php', 'secure-access-acl');

        $this->app->bindIf(SmsSender::class, fn ($app) => $app->make(
            config('secure-access.mfa.sms.sender') ?: LogSmsSender::class
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'secure-access');

        $this->app->make(Router::class)->aliasMiddleware('secure-access.mfa', EnsureMfaVerified::class);
        $this->app->make(Router::class)->aliasMiddleware('secure-access.permission', EnsureAccessPermission::class);

        if (config('secure-access-acl.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/acl-migrations');
        }

        Gate::define('secure-access', fn ($user, string $permission, ?AccessScope $subject = null) =>
            $this->app->make(AccessControl::class)->allows($user, $permission, $subject));

        Blade::if('secureAccess', fn (string $permission, ?AccessScope $subject = null) =>
            Gate::allows('secure-access', [$permission, $subject]));

        Blade::if('is', fn (string $groupKey) =>
            $this->app->make(AccessControl::class)->isMember(SecureAccess::guard()->user(), $groupKey));

        RateLimiter::for('secure-access', fn (Request $request) => Limit::perMinute(
            (int) config('secure-access.routes.throttle', 10)
        )->by($request->ip()));

        if (config('secure-access.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([UnlockAccountCommand::class, SeedCampAccessCommand::class, SeedCampDemoUsersCommand::class]);

            $this->publishes([
                __DIR__.'/../config/secure-access.php' => config_path('secure-access.php'),
                __DIR__.'/../config/coppa.php' => config_path('coppa.php'),
                __DIR__.'/../config/secure-access-acl.php' => config_path('secure-access-acl.php'),
            ], 'secure-access-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/secure-access'),
            ], 'secure-access-views');
        }
    }
}
