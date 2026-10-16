<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Tests\TestCase;

class DashboardRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('secure-access.routes.prefix', 'auth');
        $app['config']->set('secure-access.home', '/auth/dashboard');
    }

    public function test_dashboard_respects_the_configured_prefix(): void
    {
        $this->assertSame('/auth/dashboard', route('secure-access.dashboard', [], false));
        $this->get('/dashboard')->assertNotFound();
        $this->get('/auth/dashboard')->assertRedirect(route('secure-access.login'));

        $user = $this->createUser();
        $secret = $this->addTotp($user);
        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $user->mfaMethods()->sole()), [
            'code' => $this->currentTotp($secret),
        ])->assertRedirect('/auth/dashboard');
        $this->get('/auth/dashboard')->assertOk()->assertViewIs('secure-access::dashboard.index');
    }
}
