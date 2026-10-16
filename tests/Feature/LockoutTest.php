<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Event;
use SecureAccess\Events\AccountLocked;
use SecureAccess\Tests\TestCase;

class LockoutTest extends TestCase
{
    protected const LOCKED = 'This account has been locked after too many failed login attempts. Please contact support.';

    public function test_account_is_locked_after_three_failed_password_attempts(): void
    {
        Event::fake([AccountLocked::class]);
        $user = $this->createUser();
        $this->addTotp($user);

        $this->passwordStep('taylor', 'wrong')->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);
        $this->passwordStep('taylor@example.com', 'wrong')->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);
        $this->passwordStep('taylor', 'wrong')->assertSessionHasErrors(['identifier' => self::LOCKED]);

        $this->assertNotNull($user->fresh()->locked_at);
        Event::assertDispatchedTimes(AccountLocked::class, 1);

        // Even the correct password is rejected now.
        $this->passwordStep('taylor', 'password')
            ->assertRedirect(route('secure-access.login'))
            ->assertSessionHasErrors(['identifier' => self::LOCKED]);
        $this->assertGuest();
    }

    public function test_failed_mfa_codes_count_towards_the_lockout(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $method = $user->mfaMethods()->first();

        $this->passwordStep('taylor', 'wrong');
        $this->passwordStep();

        $this->post(route('secure-access.challenge.verify', $method), ['code' => '000000'])
            ->assertRedirect(route('secure-access.challenge.show', $method))
            ->assertSessionHasErrors(['code' => 'The provided code is invalid.']);

        $this->post(route('secure-access.challenge.verify', $method), ['code' => '000000'])
            ->assertRedirect(route('secure-access.login'))
            ->assertSessionHasErrors(['identifier' => self::LOCKED]);

        $this->assertTrue($user->fresh()->isLocked());
        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_successful_login_resets_the_failure_counter(): void
    {
        $user = $this->createUser();
        $secret = $this->addTotp($user);

        $this->passwordStep('taylor', 'wrong');
        $this->passwordStep('taylor', 'wrong');
        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', 1), ['code' => $this->currentTotp($secret)])->assertRedirect('/dashboard');

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
    }

    public function test_a_correct_password_alone_does_not_reset_the_counter(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);

        $this->passwordStep('taylor', 'wrong');
        $this->passwordStep('taylor', 'wrong');
        $this->passwordStep();

        $this->assertSame(2, (int) $user->fresh()->failed_login_attempts);
    }

    public function test_unlock_command(): void
    {
        $user = $this->createUser();
        foreach (range(1, 3) as $i) {
            $this->passwordStep('taylor', 'wrong');
        }
        $this->assertTrue($user->fresh()->isLocked());

        $this->artisan('secure-access:unlock', ['identifier' => 'taylor@example.com'])->assertSuccessful();

        $this->assertFalse($user->fresh()->isLocked());
        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
        $this->artisan('secure-access:unlock', ['identifier' => 'nobody'])->assertFailed();
    }

    public function test_lock_is_permanent_by_default(): void
    {
        $user = $this->createUser();
        foreach (range(1, 3) as $i) {
            $this->passwordStep('taylor', 'wrong');
        }

        $this->travel(30)->days();

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_lock_expires_when_a_duration_is_configured(): void
    {
        config(['secure-access.lockout.duration' => 15]);
        $user = $this->createUser();
        $this->addTotp($user);
        foreach (range(1, 3) as $i) {
            $this->passwordStep('taylor', 'wrong');
        }

        $this->travel(14)->minutes();
        $this->passwordStep()->assertSessionHasErrors(['identifier' => self::LOCKED]);

        $this->travel(2)->minutes();
        $this->passwordStep()->assertRedirect(route('secure-access.challenge'));
    }

    public function test_max_attempts_is_configurable(): void
    {
        config(['secure-access.lockout.max_attempts' => 5]);
        $user = $this->createUser();

        foreach (range(1, 4) as $i) {
            $this->passwordStep('taylor', 'wrong');
        }
        $this->assertFalse($user->fresh()->isLocked());

        $this->passwordStep('taylor', 'wrong');
        $this->assertTrue($user->fresh()->isLocked());
    }
}
