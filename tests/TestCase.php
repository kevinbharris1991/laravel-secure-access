<?php

namespace SecureAccess\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use SecureAccess\Contracts\SmsSender;
use SecureAccess\Models\MfaMethod;
use SecureAccess\SecureAccessServiceProvider;
use SecureAccess\Tests\Fixtures\FakeSmsSender;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Totp\Totp;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);

        Route::middleware(['web', 'secure-access.mfa'])->get('/dashboard', fn () => 'dashboard');
    }

    protected function getPackageProviders($app): array
    {
        return [SecureAccessServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('secure-access.routes.throttle', 1000);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['migrator']->path(__DIR__.'/database/migrations');
    }

    protected function createUser(array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'Taylor Otwell',
            'username' => 'taylor',
            'email' => 'taylor@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    protected function addTotp(User $user): string
    {
        $secret = app(Totp::class)->generateSecret();

        MfaMethod::create([
            'user_id' => $user->id,
            'type' => MfaMethod::TYPE_TOTP,
            'name' => 'Phone app',
            'secret' => $secret,
        ]);

        return $secret;
    }

    protected function currentTotp(string $secret): string
    {
        $totp = app(Totp::class);

        return $totp->code($secret, $totp->timestep());
    }

    protected function passwordStep(string $identifier = 'taylor', string $password = 'password')
    {
        return $this->post(route('secure-access.login.store'), [
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }
}
