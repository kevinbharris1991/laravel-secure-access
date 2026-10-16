<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AccessManagementTest extends TestCase
{
    protected function loginManager(): User
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());
        $this->verifyUser($user);

        return $user;
    }

    protected function verifyUser(User $user): void
    {
        $this->addTotp($user);
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier()]);
    }

    protected function loginWithPermission(string $key): User
    {
        $user = $this->createUser();
        $group = AccessGroup::create(['key' => 'limited-manager', 'name' => 'Limited manager']);
        $group->permissions()->attach(Permission::where('key', $key)->firstOrFail());
        $user->accessGroups()->attach($group);
        $this->verifyUser($user);

        return $user;
    }

    public function test_access_management_requires_login_and_mfa(): void
    {
        $this->get(route('secure-access.access.index'))->assertRedirect(route('secure-access.login'));
        $user = $this->createUser();
        $this->addTotp($user);
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());

        $this->actingAs($user)->get(route('secure-access.access.index'))
            ->assertRedirect(route('secure-access.login'));
    }

    public function test_mutations_require_mfa_even_for_managers(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $user->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());

        $this->actingAs($user)->post(route('secure-access.access.groups.store'), [
            'key' => 'unauthenticated-change', 'name' => 'Not allowed',
        ])->assertRedirect(route('secure-access.login'));
        $this->assertDatabaseMissing('secure_access_groups', ['key' => 'unauthenticated-change']);
    }

    public function test_verified_users_without_management_permissions_are_denied_all_actions(): void
    {
        $this->verifyUser($this->createUser());
        $group = AccessGroup::create(['key' => 'protected-group', 'name' => 'Protected']);
        $permission = Permission::create(['key' => 'protected.read_all', 'scope' => 'all']);

        $this->get(route('secure-access.access.index'))->assertForbidden();
        $this->post(route('secure-access.access.groups.store'))->assertForbidden();
        $this->put(route('secure-access.access.groups.update', $group))->assertForbidden();
        $this->delete(route('secure-access.access.groups.destroy', $group))->assertForbidden();
        $this->put(route('secure-access.access.groups.permissions', $group))->assertForbidden();
        $this->post(route('secure-access.access.permissions.store'))->assertForbidden();
        $this->put(route('secure-access.access.permissions.update', $permission))->assertForbidden();
        $this->delete(route('secure-access.access.permissions.destroy', $permission))->assertForbidden();

        $this->assertModelExists($group);
        $this->assertModelExists($permission);
    }

    public function test_index_lists_database_groups_and_permissions_with_escaped_names(): void
    {
        $this->loginManager();
        $group = AccessGroup::create(['key' => 'custom-group', 'name' => '<script>alert("group")</script>']);
        $permission = Permission::create(['key' => 'custom.read_own', 'scope' => 'own']);
        $group->permissions()->attach($permission);

        $this->get(route('secure-access.access.index'))
            ->assertOk()
            ->assertViewIs('secure-access::access.index')
            ->assertViewHas('groups', fn ($groups) => $groups->firstWhere('id', $group->id)->permissions->contains($permission))
            ->assertViewHas('permissions', fn ($permissions) => $permissions->contains($permission))
            ->assertSee($group->name)
            ->assertDontSee($group->name, false)
            ->assertSee('custom.read_own')
            ->assertSee('Save grants');
    }

    public function test_manager_can_create_update_and_delete_groups(): void
    {
        $this->loginManager();
        $this->post(route('secure-access.access.groups.store'), ['key' => 'new-group', 'name' => 'New group'])
            ->assertRedirect(route('secure-access.access.index'));
        $group = AccessGroup::where('key', 'new-group')->firstOrFail();

        $this->put(route('secure-access.access.groups.update', $group), ['key' => 'new-group', 'name' => 'Updated group'])
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertSame('Updated group', $group->fresh()->name);
        $this->put(route('secure-access.access.groups.update', $group), ['key' => 'renamed-group', 'name' => 'Updated group'])
            ->assertRedirect(route('secure-access.access.index'));

        $this->delete(route('secure-access.access.groups.destroy', $group))
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertModelMissing($group);
    }

    public function test_registration_group_keys_cannot_be_renamed_or_deleted(): void
    {
        $this->loginManager();

        foreach (['executive-director', 'guest'] as $key) {
            $group = AccessGroup::where('key', $key)->firstOrFail();
            $originalName = $group->name;
            $this->put(route('secure-access.access.groups.update', $group), [
                'key' => 'renamed-'.$key, 'name' => 'Changed',
            ])->assertSessionHasErrors('key');
            $this->assertSame($key, $group->fresh()->key);
            $this->assertSame($originalName, $group->fresh()->name);
            $this->delete(route('secure-access.access.groups.destroy', $group))->assertSessionHasErrors('key');
            $this->assertModelExists($group);
        }
    }

    public function test_registration_group_names_and_grants_remain_editable(): void
    {
        $this->loginManager();
        $permission = Permission::create(['key' => 'custom.registration-grant', 'scope' => 'all']);

        foreach (['executive-director', 'guest'] as $key) {
            $group = AccessGroup::where('key', $key)->firstOrFail();
            $this->put(route('secure-access.access.groups.update', $group), [
                'key' => $key, 'name' => 'Updated '.$key,
            ])->assertRedirect(route('secure-access.access.index'));
            $this->assertSame('Updated '.$key, $group->fresh()->name);
            $grants = $group->permissions()->pluck('secure_access_permissions.id')->all();
            $this->put(route('secure-access.access.groups.permissions', $group), [
                'permissions' => [...$grants, $permission->id],
            ])->assertRedirect(route('secure-access.access.index'));
            $this->assertTrue($group->permissions()->whereKey($permission->id)->exists());
            $this->put(route('secure-access.access.groups.permissions', $group), ['permissions' => $grants])
                ->assertRedirect(route('secure-access.access.index'));
            $this->assertFalse($group->permissions()->whereKey($permission->id)->exists());
        }
    }

    public function test_manager_can_create_update_and_delete_permissions(): void
    {
        $this->loginManager();
        $this->post(route('secure-access.access.permissions.store'), ['key' => 'new.read_all', 'scope' => 'all'])
            ->assertRedirect(route('secure-access.access.index'));
        $permission = Permission::where('key', 'new.read_all')->firstOrFail();

        $this->put(route('secure-access.access.permissions.update', $permission), ['key' => 'new.read_all', 'scope' => 'own'])
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertSame('own', $permission->fresh()->scope);
        $this->put(route('secure-access.access.permissions.update', $permission), ['key' => 'new.read_assigned', 'scope' => 'assigned'])
            ->assertRedirect(route('secure-access.access.index'));

        $this->delete(route('secure-access.access.permissions.destroy', $permission))
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertModelMissing($permission);
    }

    public function test_manager_can_replace_and_clear_group_grants(): void
    {
        $this->loginManager();
        $group = AccessGroup::create(['key' => 'new-group', 'name' => 'New group']);
        $first = Permission::create(['key' => 'custom.one', 'scope' => 'all']);
        $second = Permission::create(['key' => 'custom.two', 'scope' => 'assigned']);
        $group->permissions()->attach($first);

        $this->put(route('secure-access.access.groups.permissions', $group), ['permissions' => [$second->id]])
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertSame([$second->id], $group->permissions()->pluck('secure_access_permissions.id')->all());
        $this->put(route('secure-access.access.groups.permissions', $group))
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertSame(0, $group->permissions()->count());
    }

    public function test_group_manager_cannot_assign_permissions_through_group_crud_or_sync(): void
    {
        $user = $this->loginWithPermission('groups.manage_all');
        $group = $user->accessGroups()->sole();
        $originalGrants = $group->permissions()->pluck('secure_access_permissions.id')->all();
        $power = Permission::where('key', 'permissions.manage_all')->firstOrFail();

        $this->post(route('secure-access.access.groups.store'), [
            'key' => 'injected', 'name' => 'Injected', 'permissions' => [$power->id],
        ])->assertRedirect(route('secure-access.access.index'));
        $this->assertSame(0, AccessGroup::where('key', 'injected')->firstOrFail()->permissions()->count());
        $this->put(route('secure-access.access.groups.update', $group), [
            'key' => $group->key, 'name' => 'Renamed', 'permissions' => [$power->id],
        ])->assertRedirect(route('secure-access.access.index'));
        $this->put(route('secure-access.access.groups.permissions', $group), ['permissions' => [$power->id]])
            ->assertForbidden();
        $this->delete(route('secure-access.access.groups.destroy', $group))->assertForbidden();
        $this->post(route('secure-access.access.permissions.store'), ['key' => 'injected.power', 'scope' => 'all'])
            ->assertForbidden();
        $this->put(route('secure-access.access.permissions.update', $power), ['key' => 'renamed.power', 'scope' => 'own'])
            ->assertForbidden();
        $this->delete(route('secure-access.access.permissions.destroy', $power))->assertForbidden();
        $this->assertSame($originalGrants, $group->permissions()->pluck('secure_access_permissions.id')->all());
        $this->assertFalse($user->hasAccessPermission('permissions.manage_all'));
        $this->get(route('secure-access.access.index'))->assertOk()
            ->assertSee('Create group')->assertDontSee('Save grants')->assertDontSee('Create permission');
    }

    public function test_permission_manager_cannot_manage_groups_or_sync_grants(): void
    {
        $user = $this->loginWithPermission('permissions.manage_all');
        $group = $user->accessGroups()->sole();

        $this->post(route('secure-access.access.groups.store'))->assertForbidden();
        $this->put(route('secure-access.access.groups.update', $group))->assertForbidden();
        $this->delete(route('secure-access.access.groups.destroy', $group))->assertForbidden();
        $this->put(route('secure-access.access.groups.permissions', $group))->assertForbidden();
        $this->post(route('secure-access.access.permissions.store'), ['key' => 'custom.power', 'scope' => 'own'])
            ->assertRedirect(route('secure-access.access.index'));
        $this->get(route('secure-access.access.index'))->assertOk()
            ->assertSee('Create permission')->assertDontSee('Create group')->assertDontSee('Save grants');
    }

    public function test_group_key_and_name_validation_and_duplicate_keys(): void
    {
        $this->loginManager();
        $group = AccessGroup::create(['key' => 'custom-group', 'name' => 'Custom']);
        $other = AccessGroup::create(['key' => 'other-group', 'name' => 'Other']);
        foreach (['', 'Uppercase', '-prefix', 'invalid key', 'bad/key', str_repeat('a', 256)] as $key) {
            $this->post(route('secure-access.access.groups.store'), ['key' => $key, 'name' => 'Valid'])
                ->assertSessionHasErrors('key');
        }
        $this->post(route('secure-access.access.groups.store'), ['key' => $group->key, 'name' => 'Valid'])
            ->assertSessionHasErrors('key');
        $this->put(route('secure-access.access.groups.update', $group), ['key' => $other->key, 'name' => 'Valid'])
            ->assertSessionHasErrors('key');
        $this->post(route('secure-access.access.groups.store'), ['key' => 'valid.key-1_2', 'name' => ''])
            ->assertSessionHasErrors('name');
        $this->assertSame('custom-group', $group->fresh()->key);
    }

    public function test_permission_key_and_scope_validation_and_duplicate_keys(): void
    {
        $this->loginManager();
        $permission = Permission::create(['key' => 'custom.one', 'scope' => 'all']);
        $other = Permission::create(['key' => 'custom.two', 'scope' => 'all']);
        foreach (['', 'Uppercase', '-prefix', 'invalid key', 'bad/key', str_repeat('a', 256)] as $key) {
            $this->post(route('secure-access.access.permissions.store'), ['key' => $key, 'scope' => 'all'])
                ->assertSessionHasErrors('key');
        }
        $this->post(route('secure-access.access.permissions.store'), ['key' => $permission->key, 'scope' => 'all'])
            ->assertSessionHasErrors('key');
        $this->put(route('secure-access.access.permissions.update', $permission), ['key' => $other->key, 'scope' => 'all'])
            ->assertSessionHasErrors('key');
        foreach (['', 'global', ['all']] as $scope) {
            $this->post(route('secure-access.access.permissions.store'), ['key' => 'valid.key-1_2', 'scope' => $scope])
                ->assertSessionHasErrors('scope');
        }
        $this->assertSame('custom.one', $permission->fresh()->key);
    }

    public function test_invalid_grants_leave_existing_grants_unchanged(): void
    {
        $this->loginManager();
        $group = AccessGroup::create(['key' => 'custom-group', 'name' => 'Custom']);
        $permission = Permission::create(['key' => 'custom.read_all', 'scope' => 'all']);
        $group->permissions()->attach($permission);
        foreach (['not-an-array', [999999], ['invalid'], [$permission->id, $permission->id]] as $grants) {
            $this->put(route('secure-access.access.groups.permissions', $group), ['permissions' => $grants])
                ->assertSessionHasErrors();
            $this->assertSame([$permission->id], $group->permissions()->pluck('secure_access_permissions.id')->all());
        }
    }

    public function test_deleting_groups_and_permissions_removes_related_grants(): void
    {
        $user = $this->loginManager();
        $group = AccessGroup::create(['key' => 'custom-group', 'name' => 'Custom']);
        $permission = Permission::create(['key' => 'custom.read_all', 'scope' => 'all']);
        $group->permissions()->attach($permission);
        $user->accessGroups()->attach($group);

        $this->delete(route('secure-access.access.groups.destroy', $group))
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertDatabaseMissing('secure_access_group_grants', ['group_id' => $group->id]);
        $this->assertDatabaseMissing('secure_access_memberships', ['group_id' => $group->id]);

        $remainingGroup = AccessGroup::create(['key' => 'remaining-group', 'name' => 'Remaining']);
        $remainingGroup->permissions()->attach($permission);
        $this->delete(route('secure-access.access.permissions.destroy', $permission))
            ->assertRedirect(route('secure-access.access.index'));
        $this->assertDatabaseMissing('secure_access_group_grants', ['permission_id' => $permission->id]);
        $this->assertModelExists($remainingGroup);
    }

    public function test_revoked_permission_is_checked_again_before_each_mutation(): void
    {
        $user = $this->loginManager();
        $this->get(route('secure-access.access.index'))->assertOk();
        $user->accessGroups()->detach();

        $this->post(route('secure-access.access.groups.store'), ['key' => 'after-revocation', 'name' => 'Denied'])
            ->assertForbidden();
        $this->assertDatabaseMissing('secure_access_groups', ['key' => 'after-revocation']);
    }
}
