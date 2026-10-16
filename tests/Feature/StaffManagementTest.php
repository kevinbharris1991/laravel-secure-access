<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class StaffManagementTest extends TestCase
{
    protected function staff(string $username, string $role = 'medical-nursing'): User
    {
        $user = $this->createUser(['username' => $username, 'email' => $username.'@example.com', 'name' => ucfirst($username)]);
        $user->accessGroups()->attach(AccessGroup::where('key', $role)->firstOrFail());

        return $user;
    }

    protected function loginStaff(User $user): void
    {
        $this->addTotp($user);
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier()]);
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New Staff',
            'username' => 'newstaff',
            'email' => 'newstaff@example.com',
            'password' => 'A-strong-password-123',
            'password_confirmation' => 'A-strong-password-123',
            'group_ids' => [AccessGroup::where('key', 'medical-nursing')->value('id')],
        ];
    }

    protected function details(User $user, array $overrides = []): array
    {
        return $overrides + ['name' => $user->name, 'username' => $user->username, 'email' => $user->email];
    }

    public function test_staff_management_requires_login_and_mfa(): void
    {
        $this->get(route('secure-access.staff.index'))->assertRedirect(route('secure-access.login'));
        $admin = $this->staff('admin', 'administrative-director');
        $this->addTotp($admin);
        $this->actingAs($admin)->post(route('secure-access.staff.store'), $this->payload())
            ->assertRedirect(route('secure-access.login'));
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
    }

    public function test_admin_can_create_update_assign_reset_and_delete_actual_accounts(): void
    {
        $this->loginStaff($this->staff('admin', 'administrative-director'));
        $this->post(route('secure-access.staff.store'), $this->payload())
            ->assertRedirect(route('secure-access.staff.index'));
        $target = User::where('username', 'newstaff')->firstOrFail();
        $this->assertTrue(Hash::check('A-strong-password-123', $target->password));
        $this->assertTrue($target->accessGroups()->where('key', 'medical-nursing')->exists());
        $secret = $this->addTotp($target);
        $token = $target->remember_token;
        $group = AccessGroup::where('key', 'housekeeping')->firstOrFail();
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, [
            'name' => 'Updated Staff',
            'password' => 'Another-strong-password-456',
            'password_confirmation' => 'Another-strong-password-456',
            'group_ids' => [$group->id],
        ]))->assertRedirect(route('secure-access.staff.index'));
        $target->refresh();
        $this->assertSame('Updated Staff', $target->name);
        $this->assertTrue(Hash::check('Another-strong-password-456', $target->password));
        $this->assertNotSame($token, $target->remember_token);
        $this->assertSame([$group->id], $target->accessGroups()->pluck('secure_access_groups.id')->all());
        $this->assertSame($secret, $target->mfaMethods()->sole()->secret);
        $this->assertDatabaseHas('secure_access_camp_audits', ['action' => 'staff.create', 'module' => 'staff']);
        $this->assertDatabaseHas('secure_access_camp_audits', ['action' => 'credentials.reset', 'module' => 'staff']);
        $this->assertDatabaseHas('secure_access_camp_audits', ['action' => 'staff.roles_updated', 'module' => 'staff']);
        $auditCount = DB::table('secure_access_camp_audits')->count();
        DB::table('secure_access_memberships')->insert([
            'group_id' => $group->id, 'member_type' => 'another-model', 'member_id' => (string) $target->id,
        ]);
        $this->delete(route('secure-access.staff.destroy', $target->id))->assertRedirect(route('secure-access.staff.index'));
        $this->assertModelMissing($target);
        $this->assertDatabaseMissing('secure_access_memberships', ['member_type' => $target->getMorphClass(), 'member_id' => (string) $target->id]);
        $this->assertDatabaseHas('secure_access_memberships', ['member_type' => 'another-model', 'member_id' => (string) $target->id]);
        $this->assertSame($auditCount + 1, DB::table('secure_access_camp_audits')->count());
    }

    public function test_medical_and_guest_users_cannot_read_or_mutate_staff(): void
    {
        $target = $this->staff('target');
        foreach (['medical-nursing', 'guest'] as $role) {
            $this->loginStaff($this->staff($role, $role));
            $this->get(route('secure-access.staff.index'))->assertForbidden();
            $this->post(route('secure-access.staff.store'), $this->payload())->assertForbidden();
            $this->put(route('secure-access.staff.update', $target->id), $this->details($target))->assertForbidden();
            $this->delete(route('secure-access.staff.destroy', $target->id))->assertForbidden();
        }
        $this->assertModelExists($target);
    }

    public function test_executive_can_only_view_and_manage_executive_accounts(): void
    {
        $executive = $this->staff('executive', 'executive-director');
        $target = $this->staff('other-executive', 'executive-director');
        $medical = $this->staff('medical');
        $this->loginStaff($executive);
        $this->get(route('secure-access.staff.index'))->assertOk()
            ->assertSee($target->email)->assertDontSee($medical->email);
        $executiveGroup = AccessGroup::where('key', 'executive-director')->firstOrFail();
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, [
            'name' => 'Changed executive',
            'password' => 'Executive-password-123',
            'password_confirmation' => 'Executive-password-123',
        ]))->assertRedirect(route('secure-access.staff.index'));
        $this->assertTrue(Hash::check('Executive-password-123', $target->fresh()->password));
        $this->put(route('secure-access.staff.update', $medical->id), $this->details($medical, [
            'group_ids' => [$executiveGroup->id],
        ]))->assertForbidden();
        $this->delete(route('secure-access.staff.destroy', $medical->id))->assertForbidden();
        $this->post(route('secure-access.staff.store'), $this->payload())->assertForbidden();
        $this->post(route('secure-access.staff.store'), $this->payload(['group_ids' => [$executiveGroup->id]]))
            ->assertRedirect(route('secure-access.staff.index'));
        $this->delete(route('secure-access.staff.destroy', $target->id))->assertRedirect(route('secure-access.staff.index'));
    }

    public function test_admin_cannot_promote_self_or_take_over_executive_or_permission_manager(): void
    {
        $admin = $this->staff('admin', 'administrative-director');
        $executive = $this->staff('executive', 'executive-director');
        $this->loginStaff($admin);
        $executiveGroup = AccessGroup::where('key', 'executive-director')->firstOrFail();
        $power = AccessGroup::create(['key' => 'permission-admin', 'name' => 'Permission admin']);
        $power->permissions()->attach(Permission::where('key', 'permissions.manage_all')->firstOrFail());
        $alias = AccessGroup::create(['key' => 'executive-alias', 'name' => 'Executive alias']);
        $alias->permissions()->attach(Permission::where('key', 'executive_users.manage_all')->firstOrFail());
        foreach ([$executiveGroup, $power, $alias] as $group) {
            $this->put(route('secure-access.staff.update', $admin->id), $this->details($admin, ['group_ids' => [$group->id]]))
                ->assertForbidden();
            $this->post(route('secure-access.staff.store'), $this->payload(['group_ids' => [$group->id]]))->assertForbidden();
        }
        $this->put(route('secure-access.staff.update', $executive->id), $this->details($executive, [
            'email' => 'takeover@example.com',
        ]))->assertForbidden();
        $this->delete(route('secure-access.staff.destroy', $executive->id))->assertForbidden();
        $admin->accessGroups()->attach($power);
        $this->put(route('secure-access.staff.update', $admin->id), $this->details($admin, ['group_ids' => [$power->id]]))
            ->assertRedirect(route('secure-access.staff.index'));
    }

    public function test_password_reset_requires_exact_credential_permission_and_preserves_mfa(): void
    {
        $actor = $this->staff('manager');
        $group = AccessGroup::create(['key' => 'users-only', 'name' => 'Users manager']);
        $group->permissions()->attach(Permission::where('key', 'users.manage_all')->firstOrFail());
        $actor->accessGroups()->attach($group);
        $target = $this->staff('target');
        $this->addTotp($target);
        $hash = $target->password;
        $this->loginStaff($actor);
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, [
            'password' => 'Unauthorized-password-123',
            'password_confirmation' => 'Unauthorized-password-123',
        ]))->assertForbidden();
        $this->assertSame($hash, $target->fresh()->password);
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, ['name' => 'Allowed edit']))
            ->assertRedirect(route('secure-access.staff.index'));
        $this->assertSame(1, $target->mfaMethods()->count());
        $this->assertSame($hash, $target->fresh()->password);
    }

    public function test_granular_grants_are_exact_and_do_not_imply_other_account_operations(): void
    {
        $actor = $this->staff('limited');
        $target = $this->staff('target');
        $group = AccessGroup::create(['key' => 'staff-editor', 'name' => 'Staff editor']);
        foreach (['users.read_all', 'users.update_all'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['scope' => 'all']);
            $group->permissions()->attach($permission);
        }
        $actor->accessGroups()->attach($group);
        $this->loginStaff($actor);
        $this->get(route('secure-access.staff.index'))->assertOk()->assertSee('Save account')->assertDontSee('Create staff account');
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, ['name' => 'Edited']))
            ->assertRedirect();
        $this->post(route('secure-access.staff.store'), $this->payload())->assertForbidden();
        $this->delete(route('secure-access.staff.destroy', $target->id))->assertForbidden();
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target, ['group_ids' => []]))->assertForbidden();
        Permission::where('key', 'users.update_all')->update(['scope' => 'own']);
        $this->put(route('secure-access.staff.update', $target->id), $this->details($target))->assertForbidden();
    }

    public function test_self_deletion_and_removing_last_executive_are_rejected(): void
    {
        $executive = $this->staff('executive', 'executive-director');
        $adminGroup = AccessGroup::where('key', 'administrative-director')->firstOrFail();
        $executive->accessGroups()->attach($adminGroup);
        $this->loginStaff($executive);
        $this->delete(route('secure-access.staff.destroy', $executive->id))->assertSessionHasErrors('user');
        $this->put(route('secure-access.staff.update', $executive->id), $this->details($executive, [
            'group_ids' => [$adminGroup->id],
        ]))->assertSessionHasErrors('user');
        $this->assertTrue($executive->fresh()->accessGroups()->where('key', 'executive-director')->exists());
    }

    public function test_sensitive_views_and_writes_are_audited_without_passwords_or_mfa_secrets(): void
    {
        $admin = $this->staff('admin', 'administrative-director');
        $target = $this->staff('target');
        $secret = $this->addTotp($target);
        $this->loginStaff($admin);
        $this->get(route('secure-access.staff.index'))->assertOk()
            ->assertDontSee($target->password, false)->assertDontSee($secret, false)
            ->assertViewHas('users', fn ($users) => ! array_key_exists('password', $users->items()[0]));
        $this->assertDatabaseHas('secure_access_camp_audits', ['action' => 'staff.read_all', 'actor_id' => (string) $admin->id]);
        $this->post(route('secure-access.staff.store'), $this->payload())->assertRedirect();
        $audits = json_encode(DB::table('secure_access_camp_audits')->get());
        $this->assertStringNotContainsString($target->password, $audits);
        $this->assertStringNotContainsString($secret, $audits);
        $this->assertStringNotContainsString('A-strong-password-123', $audits);
    }

    public function test_validation_rejects_duplicates_invalid_groups_and_unconfirmed_passwords(): void
    {
        $this->loginStaff($this->staff('admin', 'administrative-director'));
        foreach ([
            ['username' => 'admin'], ['email' => 'admin@example.com'], ['email' => 'invalid'],
            ['group_ids' => [999999]], ['group_ids' => 'invalid'],
            ['password_confirmation' => 'not-confirmed'], ['password' => 'short', 'password_confirmation' => 'short'],
        ] as $invalid) {
            $this->post(route('secure-access.staff.store'), $this->payload($invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
    }

    public function test_configured_identifier_columns_and_clearing_groups_are_supported(): void
    {
        $admin = $this->staff('admin', 'administrative-director');
        $this->loginStaff($admin);
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('username', 'login_name');
            $table->renameColumn('email', 'primary_email');
        });
        config(['secure-access.username_column' => 'login_name', 'secure-access.email_column' => 'primary_email']);
        $this->post(route('secure-access.staff.store'), $this->payload())->assertRedirect();
        $target = User::where('login_name', 'newstaff')->firstOrFail();
        $this->get(route('secure-access.staff.index'))->assertOk()->assertSee('newstaff@example.com');
        $this->put(route('secure-access.staff.update', $target->id), [
            'name' => 'Custom fields', 'username' => 'changed', 'email' => 'changed@example.com', 'group_ids' => [],
        ])->assertRedirect();
        $target->refresh();
        $this->assertSame('changed', $target->login_name);
        $this->assertSame('changed@example.com', $target->primary_email);
        $this->assertSame(0, $target->accessGroups()->count());
    }

    public function test_custom_host_user_model_with_uuid_keys_uses_morph_safe_memberships_and_audits(): void
    {
        $this->loginStaff($this->staff('admin', 'administrative-director'));
        Schema::create('uuid_staff_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        config(['secure-access.user_model' => UuidStaffUser::class]);
        $this->post(route('secure-access.staff.store'), $this->payload())->assertRedirect();
        $target = UuidStaffUser::where('username', 'newstaff')->firstOrFail();
        $this->assertFalse(is_numeric($target->id));
        $this->assertTrue(Hash::check('A-strong-password-123', $target->password));
        $this->assertDatabaseHas('secure_access_memberships', [
            'member_type' => $target->getMorphClass(), 'member_id' => $target->id,
        ]);
        $this->assertDatabaseHas('secure_access_camp_audits', ['action' => 'staff.create', 'record_id' => null]);
        $this->get(route('secure-access.staff.index'))->assertOk()->assertSee('newstaff@example.com');
        $this->put(route('secure-access.staff.update', $target->id), [
            'name' => 'UUID staff', 'username' => 'uuid-staff', 'email' => 'uuid-staff@example.com',
        ])->assertRedirect();
        $this->delete(route('secure-access.staff.destroy', $target->id))->assertRedirect();
        $this->assertModelMissing($target);
        $this->assertDatabaseMissing('secure_access_memberships', ['member_type' => $target->getMorphClass(), 'member_id' => $target->id]);
    }

    public function test_optional_or_missing_identifier_columns_are_rejected_without_database_errors(): void
    {
        $this->loginStaff($this->staff('admin', 'administrative-director'));
        $target = $this->staff('target');
        foreach ([
            ['secure-access.username_column' => null, 'secure-access.email_column' => 'email'],
            ['secure-access.username_column' => 'username', 'secure-access.email_column' => null],
            ['secure-access.username_column' => 'missing_username', 'secure-access.email_column' => 'email'],
            ['secure-access.username_column' => 'username', 'secure-access.email_column' => 'missing_email'],
        ] as $configuration) {
            config($configuration);
            $this->get(route('secure-access.staff.index'))->assertStatus(422)
                ->assertSee('Staff management requires configured username and email columns');
            $this->post(route('secure-access.staff.store'), $this->payload())->assertStatus(422);
            $this->put(route('secure-access.staff.update', $target->id), $this->details($target))->assertStatus(422);
        }
        $this->assertSame('Target', $target->fresh()->name);
        $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
    }

    public function test_account_mutations_roll_back_when_required_audit_cannot_be_written(): void
    {
        $this->loginStaff($this->staff('admin', 'administrative-director'));
        $target = $this->staff('target');
        $previousGroups = $target->accessGroups()->pluck('secure_access_groups.id')->all();
        Schema::rename('secure_access_camp_audits', 'unavailable_staff_audits');
        try {
            $this->post(route('secure-access.staff.store'), $this->payload())->assertServerError();
            $this->assertDatabaseMissing('users', ['username' => 'newstaff']);
            $this->put(route('secure-access.staff.update', $target->id), $this->details($target, [
                'name' => 'Must roll back', 'group_ids' => [],
            ]))->assertServerError();
            $this->assertSame('Target', $target->fresh()->name);
            $this->assertSame($previousGroups, $target->accessGroups()->pluck('secure_access_groups.id')->all());
            $this->delete(route('secure-access.staff.destroy', $target->id))->assertServerError();
            $this->assertModelExists($target);
            $this->assertSame($previousGroups, $target->accessGroups()->pluck('secure_access_groups.id')->all());
        } finally {
            Schema::rename('unavailable_staff_audits', 'secure_access_camp_audits');
        }
    }
}

class UuidStaffUser extends User
{
    use HasUuids;

    protected $table = 'uuid_staff_users';
}
