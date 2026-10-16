<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_login_page_renders(): void
    {
        $this->get(route('secure-access.login'))->assertOk()->assertSee('Email or Username');
    }
}
