<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Tests\TestCase;

class RegistrationRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('secure-access.routes.prefix', 'auth');
    }

    public function test_registration_routes_respect_the_configured_prefix(): void
    {
        $this->assertSame('/auth/register', route('secure-access.register', [], false));
        $this->assertSame('/auth/register', route('secure-access.register.store', [], false));
        $this->get('/auth/register')->assertOk()->assertSee(route('secure-access.register.store'));
        $this->get('/register')->assertNotFound();
        $this->post('/auth/register', [])->assertSessionHasErrors('email');
    }
}
