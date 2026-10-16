<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Authorization\ScopeContext;
use SecureAccess\Dashboard\Dashboard;
use SecureAccess\Dashboard\DashboardItem;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AccessControlTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // ACL is enabled by default
        $app['config']->set('secure-access-acl.enabled', true);
    }

    protected function grant(string $key, string $scope = 'all', string $groupKey = 'staff'): AccessGroup
    {
        $group = AccessGroup::firstOrCreate(['key' => $groupKey], ['name' => ucfirst($groupKey)]);
        $permission = Permission::firstOrCreate(['key' => $key], ['scope' => $scope]);
        $group->permissions()->syncWithoutDetaching([$permission->id]);

        return $group;
    }

    public function test_acl_is_enabled_by_default_and_loads_migrations(): void
    {
        // Verify ACL is enabled by default
        $this->assertTrue(config('secure-access-acl.enabled'));

        // Verify tables exist (migration creates them)
        $this->assertDatabaseHas('secure_access_permissions', []);
        $this->assertDatabaseHas('secure_access_groups', []);
    }

    public function test_migration_initializes_default_camp_groups_and_permissions(): void
    {
        // The migration should have seeded exactly 12 default groups
        $this->assertDatabaseCount('secure_access_groups', 12);

        // Verify specific groups were created
        $this->assertDatabaseHas('secure_access_groups', ['key' => 'executive-director']);
        $this->assertDatabaseHas('secure_access_groups', ['key' => 'administrative-director']);
        $this->assertDatabaseHas('secure_access_groups', ['key' => 'guest']);

        // Verify permissions exist
        $this->assertTrue(Permission::where('key', 'system.read_all')->exists());
        $this->assertTrue(Permission::where('key', 'users.manage_all')->exists());
    }

    public function test_default_groups_have_bootstrap_permissions(): void
    {
        $executiveDirector = AccessGroup::where('key', 'executive-director')->firstOrFail();
        $administrativeDirector = AccessGroup::where('key', 'administrative-director')->firstOrFail();

        // Both should have invitations.create_all
        $this->assertTrue($executiveDirector->permissions()->where('key', 'invitations.create_all')->exists());
        $this->assertTrue($administrativeDirector->permissions()->where('key', 'invitations.create_all')->exists());

        // Executive director should have group and permission management
        $this->assertTrue($executiveDirector->permissions()->where('key', 'groups.manage_all')->exists());
        $this->assertTrue($executiveDirector->permissions()->where('key', 'permissions.manage_all')->exists());

        // Administrative director should have group management but not permission management
        $this->assertTrue($administrativeDirector->permissions()->where('key', 'groups.manage_all')->exists());
        $this->assertFalse($administrativeDirector->permissions()->where('key', 'permissions.manage_all')->exists());
    }

    public function test_migration_grants_bootstrap_permissions_to_existing_groups(): void
    {
        $user = $this->createUser();
        $administrator = AccessGroup::where('key', 'administrative-director')->sole();
        $administrator->update(['key' => 'administrative-directors']);
        $user->accessGroups()->attach($administrator);
        Permission::whereIn('key', ['invitations.create_all', 'permissions.manage_all'])->delete();
        $administrator->permissions()->detach(Permission::where('key', 'groups.manage_all')->value('id'));
        $custom = $this->grant('custom.read_all', 'all', 'administrative-directors');

        $migration = require __DIR__.'/../../database/acl-migrations/2024_01_01_000007_initialize_camp_access.php';
        $migration->up();

        $executiveDirector = AccessGroup::where('key', 'executive-director')->firstOrFail();
        $this->assertTrue($executiveDirector->permissions()->where('key', 'invitations.create_all')->exists());
        $this->assertTrue($executiveDirector->permissions()->where('key', 'groups.manage_all')->exists());
        $this->assertTrue($executiveDirector->permissions()->where('key', 'permissions.manage_all')->exists());

        $administrativeDirector = AccessGroup::where('key', 'administrative-director')->firstOrFail();
        $this->assertTrue($administrativeDirector->permissions()->where('key', 'invitations.create_all')->exists());
        $this->assertFalse($administrativeDirector->permissions()->where('key', 'groups.manage_all')->exists());
        $this->assertFalse($administrativeDirector->permissions()->where('key', 'permissions.manage_all')->exists());
        $this->assertSame($custom->id, $administrativeDirector->id);
        $this->assertTrue($user->hasAccessPermission('custom.read_all'));
        $this->assertSame(['administrative-director'], $user->accessGroups()->pluck('key')->all());
    }

    public function test_effective_permissions_are_the_union_of_current_memberships(): void
    {
        $user = $this->createUser();
        $one = $this->grant('reports.read_all');
        $two = $this->grant('content.update_all', 'all', 'editors');
        $user->accessGroups()->attach([$one->id, $two->id]);

        $this->assertTrue($user->hasAccessPermission('reports.read_all'));
        $this->assertTrue(Gate::forUser($user)->allows('secure-access', ['content.update_all']));
        $this->assertFalse($user->hasAccessPermission('unknown'));
        $this->assertFalse(app(AccessControl::class)->allows(null, 'reports.read_all'));

        $user->load('accessGroups');
        $user->accessGroups()->detach($one);
        $this->assertFalse($user->hasAccessPermission('reports.read_all'));
        $two->permissions()->detach();
        $this->assertFalse($user->hasAccessPermission('content.update_all'));
    }

    public function test_scoped_permissions_require_matching_server_side_context(): void
    {
        $user = $this->createUser();
        $group = $this->grant('bookings.read_own', 'own');
        $this->grant('care.read_assigned', 'assigned');
        $this->grant('reports.read_aggregate');
        $this->grant('invalid', 'unsupported');
        $user->accessGroups()->attach($group);

        $this->assertTrue($user->hasAccessPermission('bookings.read_own', new ScopeContext((string) $user->id)));
        $this->assertFalse($user->hasAccessPermission('bookings.read_own', new ScopeContext($user->id + 1)));
        $this->assertFalse($user->hasAccessPermission('bookings.read_own'));
        $this->assertTrue($user->hasAccessPermission('care.read_assigned', new ScopeContext(null, [$user->id])));
        $this->assertFalse($user->hasAccessPermission('care.read_assigned', new ScopeContext($user->id)));
        $this->assertFalse($user->hasAccessPermission('care.read_assigned'));
        $this->assertTrue($user->hasAccessPermission('reports.read_aggregate'));
        $this->assertFalse($user->hasAccessPermission('reports.read_all'));
        $this->assertFalse($user->hasAccessPermission('invalid'));
    }

    public function test_overlapping_grants_survive_one_group_revocation_and_cascade_on_delete(): void
    {
        $user = $this->createUser();
        $one = $this->grant('reports.read_all');
        $two = $this->grant('reports.read_all', 'all', 'auditors');
        $user->accessGroups()->attach([$one->id, $two->id]);
        $one->permissions()->detach();

        $this->assertTrue($user->hasAccessPermission('reports.read_all'));
        $two->delete();
        $this->assertFalse($user->hasAccessPermission('reports.read_all'));
        $this->assertDatabaseMissing('secure_access_memberships', ['group_id' => $two->id]);
        $this->assertDatabaseMissing('secure_access_group_grants', ['group_id' => $two->id]);
    }

    public function test_disabled_acl_denies_even_existing_grants(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach($this->grant('reports.read_all'));
        config(['secure-access-acl.enabled' => false]);

        $this->assertFalse($user->hasAccessPermission('reports.read_all'));
    }

    public function test_memberships_do_not_cross_user_types_and_are_removed_on_deletion(): void
    {
        $user = $this->createUser();
        $group = $this->grant('reports.read_all');
        $user->accessGroups()->attach($group);
        $otherType = new class extends User
        {
            public function getMorphClass()
            {
                return 'another-user-type';
            }
        };
        $otherType->setRawAttributes($user->getAttributes());
        $otherType->exists = true;

        $this->assertFalse($otherType->hasAccessPermission('reports.read_all'));
        $user->delete();
        $this->assertDatabaseCount('secure_access_memberships', 0);
        $this->assertFalse($user->hasAccessPermission('reports.read_all'));
    }

    public function test_seed_command_only_initializes_empty_database(): void
    {
        // DB is already seeded by migration; verify it's populated
        $initialGroupCount = AccessGroup::count();
        $this->assertGreaterThan(0, $initialGroupCount);

        // Running seed-camp command should do nothing (DB not empty)
        $this->artisan('secure-access:seed-camp')->assertSuccessful();

        // Count should not change
        $this->assertSame($initialGroupCount, AccessGroup::count());
    }

    public function test_seeding_never_removes_existing_grants(): void
    {
        // DB is already seeded; add a custom permission and grant
        $customPerm = Permission::create(['key' => 'custom.action_all', 'scope' => 'all']);
        $guestGroup = AccessGroup::where('key', 'guest')->firstOrFail();
        $guestGroup->permissions()->attach($customPerm);

        $initialGrantCount = \Illuminate\Support\Facades\DB::table('secure_access_group_grants')->count();

        // Run seed-camp command (should do nothing since DB is not empty)
        $this->artisan('secure-access:seed-camp')->assertSuccessful();

        // The custom grant should still exist
        $this->assertDatabaseHas('secure_access_group_grants', [
            'group_id' => $guestGroup->id,
            'permission_id' => $customPerm->id,
        ]);

        // Grant count should be unchanged
        $this->assertSame($initialGrantCount, \Illuminate\Support\Facades\DB::table('secure_access_group_grants')->count());
    }

    public function test_seeding_fails_gracefully_when_acl_disabled(): void
    {
        config(['secure-access-acl.enabled' => false]);

        $this->artisan('secure-access:seed-camp')->assertFailed();
    }

    public function test_existing_database_remains_untouched(): void
    {
        // Verify default data is present
        $guestGroup = AccessGroup::where('key', 'guest')->firstOrFail();
        $initialGrantCount = $guestGroup->permissions()->count();

        // Remove a permission from guest group
        $permission = Permission::where('key', 'bookings.create_own')->firstOrFail();
        $guestGroup->permissions()->detach($permission->id);

        $afterRemoveCount = $guestGroup->permissions()->count();
        $this->assertLessThan($initialGrantCount, $afterRemoveCount);

        // Run seed-camp command (should not restore the removed permission)
        $this->artisan('secure-access:seed-camp')->assertSuccessful();

        // The permission should still be removed
        $this->assertFalse($guestGroup->permissions()->where('id', $permission->id)->exists());
        $this->assertSame($afterRemoveCount, $guestGroup->permissions()->count());
    }

    public function test_migration_does_not_broaden_scoped_permissions(): void
    {
        $permission = Permission::where('key', 'invitations.create_all')->sole();
        $permission->groups()->detach();
        $permission->update(['scope' => 'own']);
        $migration = require __DIR__.'/../../database/acl-migrations/2024_01_01_000007_initialize_camp_access.php';
        $migration->up();

        $this->assertSame('own', $permission->fresh()->scope);
        $this->assertSame(0, $permission->groups()->count());
    }

    public function test_migration_merges_legacy_memberships_and_grants_without_duplicates(): void
    {
        $user = $this->createUser();
        $legacy = $this->grant('custom.read_all', 'all', 'administrative-directors');
        $current = AccessGroup::where('key', 'administrative-director')->sole();
        $user->accessGroups()->attach([$legacy->id, $current->id]);
        $migration = require __DIR__.'/../../database/acl-migrations/2024_01_01_000007_initialize_camp_access.php';
        $migration->up();

        $this->assertDatabaseMissing('secure_access_groups', ['key' => 'administrative-directors']);
        $this->assertSame(['administrative-director'], $user->accessGroups()->pluck('key')->all());
        $this->assertTrue($user->hasAccessPermission('custom.read_all'));
        $this->assertSame(1, DB::table('secure_access_memberships')->where('member_id', (string) $user->id)->count());
    }

    public function test_upgrade_adds_registration_groups_to_a_custom_acl_without_restoring_other_defaults(): void
    {
        AccessGroup::query()->delete();
        Permission::query()->delete();
        $custom = $this->grant('custom.read_all', 'all', 'custom');
        $migration = require __DIR__.'/../../database/acl-migrations/2024_01_01_000007_initialize_camp_access.php';
        $migration->up();

        $this->assertDatabaseHas('secure_access_groups', ['key' => 'executive-director']);
        $this->assertDatabaseHas('secure_access_groups', ['key' => 'guest']);
        $this->assertDatabaseCount('secure_access_groups', 4);
        $this->assertTrue($custom->permissions()->where('key', 'custom.read_all')->exists());
        $this->assertFalse(Permission::where('key', 'clinical_incidents.manage_all')->exists());
    }

    public function test_guest_grant_applications_are_owned_and_cannot_be_approved_or_read_globally(): void
    {
        // Use pre-seeded guest group
        $guest = $this->createUser();
        $group = AccessGroup::where('key', 'guest')->firstOrFail();
        $guest->accessGroups()->attach($group);
        $own = new ScopeContext($guest->id);
        $other = new ScopeContext($guest->id + 1);

        foreach (['create', 'read', 'update', 'submit'] as $action) {
            $key = 'grant_applications.'.$action.'_own';
            $this->assertTrue($guest->hasAccessPermission($key, $own));
            $this->assertFalse($guest->hasAccessPermission($key, $other));
            $this->assertFalse($guest->hasAccessPermission($key));
        }

        $this->assertFalse($guest->hasAccessPermission('grant_applications.read_all'));
        $this->assertFalse($guest->hasAccessPermission('grant_applications.approve_all', $own));
        $this->assertFalse($guest->hasAccessPermission('clinical_incidents.manage_all'));
        $this->assertFalse($guest->hasAccessPermission('groups.manage_all'));
    }

    public function test_dashboard_filters_before_executing_data_callbacks_and_preserves_keys(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach($this->grant('reports.read_aggregate'));
        $loads = 0;
        $items = [
            'summary' => new DashboardItem('reports.read_aggregate', function () use (&$loads) {
                $loads++;

                return ['count' => 10];
            }),
            'clinical' => new DashboardItem('clinical.read_all', function () {
                $this->fail('Unauthorized data must not be loaded.');
            }),
        ];

        $this->assertSame(['summary' => ['count' => 10]], app(Dashboard::class)->resolveFor($user, $items));
        $this->assertSame([], app(Dashboard::class)->resolveFor(null, $items));
        $this->assertSame(1, $loads);
        $this->assertFalse($items['clinical']->visibleTo($user));
        $this->expectException(AuthorizationException::class);
        $items['clinical']->resolveFor($user);
    }

    public function test_blade_helpers_hide_denied_content_and_accept_scope_context(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach($this->grant('bookings.read_own', 'own'));
        $this->actingAs($user);

        $template = "@secureAccess('bookings.read_own', \$subject)visible@else hidden@endsecureAccess";
        $this->assertSame('visible', Blade::render($template, ['subject' => new ScopeContext($user->id)]));
        $this->assertSame(' hidden', Blade::render($template, ['subject' => new ScopeContext($user->id + 1)]));
        $this->assertSame('visible', Blade::render("@can('secure-access', ['bookings.read_own', \$subject])visible@endcan", [
            'subject' => new ScopeContext($user->id),
        ]));
    }

    public function test_dashboard_visibility_honors_the_item_scope(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach($this->grant('bookings.read_own', 'own'));
        $denied = fn () => $this->fail('An out-of-scope item must not resolve.');
        $items = [
            'own' => new DashboardItem('bookings.read_own', fn () => 'mine', new ScopeContext($user->id)),
            'other' => new DashboardItem('bookings.read_own', $denied, new ScopeContext($user->id + 1)),
            'missing' => new DashboardItem('bookings.read_own', $denied),
        ];

        $this->assertSame(['own' => 'mine'], app(Dashboard::class)->resolveFor($user, $items));
    }

    public function test_route_checks_precede_handlers_and_use_bound_scope_subjects(): void
    {
        $user = $this->createUser();
        $group = $this->grant('bookings.read_own', 'own');
        Route::bind('booking', fn ($id) => new ScopeContext($id));
        Route::middleware(['web', 'secure-access.permission:bookings.read_own,booking'])
            ->get('/acl/bookings/{booking}', fn () => 'protected');
        Route::middleware(['web', 'secure-access.permission:bookings.read_own,missing'])
            ->get('/acl/missing', fn () => 'must not run');
        Route::middleware(['web', 'secure-access.permission:bookings.read_own,raw'])
            ->get('/acl/unbound/{raw}', fn () => 'must not run');

        $this->getJson('/acl/bookings/'.$user->id)->assertForbidden();
        $this->actingAs($user)->get('/acl/bookings/'.$user->id)->assertForbidden();
        $user->accessGroups()->attach($group);
        $this->get('/acl/bookings/'.$user->id)->assertOk()->assertSee('protected');
        $this->get('/acl/bookings/'.($user->id + 1))->assertForbidden();
        $this->get('/acl/missing')->assertForbidden();
        $this->get('/acl/unbound/'.$user->id)->assertForbidden();
    }

    public function test_acl_does_not_bypass_the_existing_mfa_middleware(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $user->accessGroups()->attach($this->grant('reports.read_all'));
        Route::middleware(['web', 'secure-access.mfa', 'secure-access.permission:reports.read_all'])
            ->get('/acl/reports', fn () => 'report');

        $this->actingAs($user)->get('/acl/reports')->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }
}
