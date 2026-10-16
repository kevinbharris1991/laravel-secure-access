<?php

namespace SecureAccess\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use SecureAccess\Authorization\CampDefaults;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\TestCase;

class CampDashboardTest extends TestCase
{
    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], array_keys(CampDefaults::groups()));
    }

    #[DataProvider('roles')]
    public function test_dashboard_links_follow_each_roles_exact_permissions(string $role): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', $role)->sole());
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);
        $visible = Modules::visible($user);
        $response = $this->get(route('secure-access.dashboard'))->assertOk();
        foreach (Modules::all() as $module => $definition) {
            $url = route('secure-access.camp.index', $module);
            if (isset($visible[$module])) {
                $response->assertSee($url);
            } else {
                $response->assertDontSee($url);
            }
        }
    }

    public function test_module_routes_respect_mfa_and_route_prefix(): void
    {
        $this->get(route('secure-access.camp.index', 'health_profiles'))
            ->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.guest.index'))->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.staff.index'))->assertRedirect(route('secure-access.login'));
    }

    public function test_authorized_module_forms_render_validation_feedback(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'medical-nursing')->sole());
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);
        $this->get(route('secure-access.camp.create', 'medications'))->assertOk()->assertSee('name="name"', false);
        $this->post(route('secure-access.camp.store', 'medications'), [])->assertSessionHasErrors('name');
        $this->get(route('secure-access.camp.create', 'medications'))->assertOk()->assertSee('name');
    }

    public function test_access_settings_views_and_changes_are_audited(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->sole());
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);
        $this->get(route('secure-access.access.index'))->assertOk();
        $this->assertDatabaseHas('secure_access_camp_audits', ['module' => 'access_settings', 'action' => 'read', 'actor_id' => (string) $user->id]);
        $this->post(route('secure-access.access.groups.store'), ['key' => 'new-team', 'name' => 'New team'])->assertRedirect();
        $this->assertDatabaseHas('secure_access_camp_audits', ['module' => 'access_settings', 'action' => 'update', 'actor_id' => (string) $user->id]);
    }

    public function test_blade_uses_the_configured_verified_guard(): void
    {
        config(['auth.guards.camp' => ['driver' => 'session', 'provider' => 'users'], 'secure-access.guard' => 'camp']);
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        \Illuminate\Support\Facades\Auth::guard('camp')->login($user);
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id])
            ->get(route('secure-access.dashboard'))->assertOk()
            ->assertSee(route('secure-access.guest.index'));
    }

    public function test_normal_seeding_does_not_restore_revoked_module_grants(): void
    {
        $group = AccessGroup::where('key', 'medical-nursing')->sole();
        $permission = Permission::where('key', 'medications.create_all')->sole();
        $group->permissions()->detach($permission);
        $this->artisan('secure-access:seed-camp')->assertSuccessful();
        $this->assertFalse($group->permissions()->whereKey($permission->id)->exists());
        $this->artisan('secure-access:seed-camp', ['--grant-modules' => true])->assertSuccessful();
        $this->assertTrue($group->permissions()->whereKey($permission->id)->exists());
    }

    public function test_demo_users_require_non_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('secure-access:demo-users')->assertFailed();
    }

    public function test_demo_users_have_no_baked_in_password_and_are_not_overwritten(): void
    {
        $this->artisan('secure-access:demo-users')
            ->expectsQuestion('Password for the demo accounts (at least 12 characters)', 'local-only-example-passphrase')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 12);
        $this->assertDatabaseHas('users', ['username' => 'camp-guest', 'email' => 'camp-guest@example.test']);
        $this->artisan('secure-access:demo-users')
            ->expectsQuestion('Password for the demo accounts (at least 12 characters)', 'a-different-local-passphrase')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 12);
    }
}
