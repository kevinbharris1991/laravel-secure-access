<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\MfaMethod;
use SecureAccess\Tests\TestCase;

class SmsTest extends TestCase
{
    public function test_first_login_enrolls_a_phone_number_and_logs_in(): void
    {
        $user = $this->createUser();
        $this->passwordStep();

        $this->get(route('secure-access.setup.create', 'sms'))->assertOk()->assertSee('Mobile phone number');

        $this->post(route('secure-access.setup.sms.send'), ['phone' => '555-0123'])->assertSessionHasErrors('phone');

        $this->post(route('secure-access.setup.sms.send'), ['phone' => '+15555550123'])
            ->assertRedirect(route('secure-access.setup.create', 'sms'));
        $this->assertCount(1, $this->sms->messages);
        $this->assertSame('+15555550123', $this->sms->messages[0]['phone']);

        $this->get(route('secure-access.setup.create', 'sms'))->assertOk()->assertSee('Confirm');

        $this->post(route('secure-access.setup.store', 'sms'), ['code' => 'nope'])->assertSessionHasErrors('code');
        $this->post(route('secure-access.setup.store', 'sms'), ['code' => $this->sms->lastCode()])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $method = $user->mfaMethods()->sole();
        $this->assertSame(MfaMethod::TYPE_SMS, $method->type);
        $this->assertSame('+15555550123', $method->secret);
    }

    public function test_sms_challenge_logs_the_user_in(): void
    {
        $user = $this->createUser();
        $method = MfaMethod::create(['user_id' => $user->id, 'type' => 'sms', 'name' => 'Mobile', 'secret' => '+15555550123']);

        $this->passwordStep();
        $this->get(route('secure-access.challenge.show', $method))->assertOk()->assertSee('Send code');
        $this->assertCount(0, $this->sms->messages);

        $this->post(route('secure-access.challenge.verify', $method), ['code' => '123456'])
            ->assertSessionHasErrors(['code' => 'Your code has expired. Please request a new code.']);

        $this->post(route('secure-access.challenge.send', $method))->assertRedirect(route('secure-access.challenge.show', $method));
        $this->assertCount(1, $this->sms->messages);

        // Resending is throttled.
        $this->post(route('secure-access.challenge.send', $method));
        $this->assertCount(1, $this->sms->messages);

        $this->post(route('secure-access.challenge.verify', $method), ['code' => $this->sms->lastCode()])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_sms_codes_are_single_use_and_expire(): void
    {
        $user = $this->createUser();
        $method = MfaMethod::create(['user_id' => $user->id, 'type' => 'sms', 'name' => 'Mobile', 'secret' => '+15555550123']);

        $this->passwordStep();
        $this->post(route('secure-access.challenge.send', $method));
        $code = $this->sms->lastCode();

        $this->travel(11)->minutes();
        $this->passwordStep();

        $this->post(route('secure-access.challenge.verify', $method), ['code' => $code])
            ->assertSessionHasErrors(['code' => 'Your code has expired. Please request a new code.']);
        $this->assertGuest();
    }
}
