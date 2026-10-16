<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function verifyUser(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier()]);
    }

    public function test_dashboard_requires_login_and_mfa(): void
    {
        $this->assertSame('/dashboard', route('secure-access.dashboard', [], false));
        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
        $this->getJson('/dashboard')->assertUnauthorized();

        $user = $this->createUser();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
        $this->actingAs($user)->getJson('/dashboard')->assertForbidden();
    }

    public function test_users_without_groups_get_a_working_dashboard_and_security_link(): void
    {
        $this->verifyUser($this->createUser());

        $this->get('/dashboard')
            ->assertOk()
            ->assertViewIs('secure-access::dashboard.index')
            ->assertViewHas('items', [])
            ->assertViewHas('groups', fn ($groups) => $groups->isEmpty())
            ->assertSee('No group tools are available')
            ->assertSee(route('secure-access.security'))
            ->assertDontSee(route('secure-access.access.index'))
            ->assertDontSee(route('secure-access.invitations.index'));
    }

    public function test_executive_dashboard_shows_all_available_management_tools(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->sole());
        $this->verifyUser($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Executive Director')
            ->assertDontSee('Medical &amp; Nursing Staff', false)
            ->assertViewHas('items', fn ($items) => array_keys($items) === ['groups', 'permissions', 'invitations'])
            ->assertSee('Manage groups')
            ->assertSee('Manage permissions')
            ->assertSee('Invite users')
            ->assertSee(route('secure-access.access.index'))
            ->assertSee(route('secure-access.invitations.index'));
    }

    public function test_administrative_dashboard_omits_permission_management(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->sole());
        $this->verifyUser($user);

        $this->get('/dashboard')->assertOk()
            ->assertSee('Administrative Director')
            ->assertSee('Manage groups')
            ->assertSee('Invite users')
            ->assertDontSee('Manage permissions');
    }

    public function test_guest_dashboard_does_not_expose_administrative_tools(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        $this->verifyUser($user);

        $this->get('/dashboard')->assertOk()
            ->assertSee('Guest')
            ->assertSee('Manage security')
            ->assertDontSee('Manage groups')
            ->assertDontSee('Manage permissions')
            ->assertDontSee('Invite users');
        $this->get(route('secure-access.access.index'))->assertForbidden();
        $this->get(route('secure-access.invitations.index'))->assertForbidden();
    }

    public function test_tools_follow_combined_custom_group_grants_and_live_revocations(): void
    {
        $user = $this->createUser();
        $groups = AccessGroup::create(['key' => 'custom-groups', 'name' => 'Custom groups']);
        $invitations = AccessGroup::create(['key' => 'custom-invitations', 'name' => 'Custom invitations']);
        $groups->permissions()->attach(Permission::where('key', 'groups.manage_all')->sole());
        $invitations->permissions()->attach(Permission::where('key', 'invitations.create_all')->sole());
        $user->accessGroups()->attach([$groups->id, $invitations->id]);
        $user->load('accessGroups');
        $this->verifyUser($user);

        $this->get('/dashboard')->assertOk()
            ->assertSee('Custom groups')->assertSee('Custom invitations')
            ->assertSee('Manage groups')->assertSee('Invite users')->assertDontSee('Manage permissions');

        $groups->permissions()->detach();
        $this->get('/dashboard')->assertOk()->assertDontSee('Manage groups')->assertSee('Invite users');
        $this->get(route('secure-access.access.index'))->assertForbidden();

        $user->accessGroups()->detach($invitations);
        $this->get('/dashboard')->assertOk()
            ->assertDontSee('Custom invitations')->assertDontSee('Invite users')
            ->assertSee('No group tools are available');
    }

    public function test_group_memberships_are_scoped_by_member_type_and_names_are_escaped(): void
    {
        $user = $this->createUser();
        $group = AccessGroup::create(['key' => 'escaped', 'name' => '<script>alert("group")</script>']);
        $user->accessGroups()->attach($group);
        DB::table('secure_access_memberships')->insert([
            'group_id' => AccessGroup::where('key', 'executive-director')->value('id'),
            'member_type' => 'another-model',
            'member_id' => (string) $user->id,
        ]);
        $this->verifyUser($user);

        $this->get('/dashboard')->assertOk()
            ->assertSee($group->name)->assertDontSee($group->name, false)
            ->assertDontSee('Executive Director')->assertDontSee('Manage groups');
    }

    public function test_dashboard_does_not_query_acl_tables_when_acl_is_disabled(): void
    {
        $user = $this->createUser();
        $this->verifyUser($user);
        config(['secure-access-acl.enabled' => false]);
        DB::listen(function ($query) {
            $this->assertStringNotContainsString('secure_access_', $query->sql);
        });

        $this->get('/dashboard')->assertOk()
            ->assertSee('Manage security')
            ->assertViewHas('items', [])
            ->assertViewHas('groups', fn ($groups) => $groups->isEmpty());
    }

    public function test_dashboard_uses_the_configured_guard(): void
    {
        config([
            'auth.guards.members' => ['driver' => 'session', 'provider' => 'users'],
            'secure-access.guard' => 'members',
        ]);
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->sole());
        $this->verifyUser($user);
        $this->app['auth']->shouldUse('web');

        $this->get('/dashboard')->assertOk()->assertSee('Executive Director')->assertSee('Manage groups');
    }
}
