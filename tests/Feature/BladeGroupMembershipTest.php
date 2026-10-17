<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class BladeGroupMembershipTest extends TestCase
{
    private const BRANCHES = <<<'BLADE'
@is('guest')
guest
@elseis('executive-director')
director
@else
other
@endis
BLADE;

    public function test_membership_branches_follow_current_memberships_not_loaded_relationships(): void
    {
        $user = $this->createUser();
        $guest = AccessGroup::where('key', 'guest')->sole();
        $director = AccessGroup::where('key', 'executive-director')->sole();
        $this->actingAs($user);

        $this->assertSame('other', trim(Blade::render(self::BRANCHES)));

        $user->accessGroups()->attach($guest);
        $user->load('accessGroups');
        $this->assertSame('guest', trim(Blade::render(self::BRANCHES)));

        $user->accessGroups()->detach($guest);
        $user->accessGroups()->attach($director);
        $this->assertSame('director', trim(Blade::render(self::BRANCHES)));

        $user->accessGroups()->detach($director);
        $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
    }

    public function test_unauthenticated_users_are_not_guest_group_members(): void
    {
        $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
        $this->assertSame('unauthenticated', trim(Blade::render('@guest unauthenticated @endguest')));
    }

    public function test_disabled_acl_does_not_query_acl_tables_even_for_members(): void
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        $this->actingAs($user);
        config(['secure-access-acl.enabled' => false]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_membership_uses_configured_guard_instead_of_default_guard(): void
    {
        config(['auth.guards.camp' => ['driver' => 'session', 'provider' => 'users'], 'secure-access.guard' => 'camp']);
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        Auth::guard('camp')->login($user);
        Auth::shouldUse('web');

        $this->assertNull(Auth::guard('web')->user());
        $this->assertSame('guest', trim(Blade::render(self::BRANCHES)));

        Auth::guard('camp')->logout();
        Auth::guard('web')->login($user);
        $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
    }

    public function test_membership_matches_group_key_not_display_name_or_permission(): void
    {
        $user = $this->createUser();
        $group = AccessGroup::create(['key' => 'custom-team', 'name' => 'guest']);
        $user->accessGroups()->attach($group);
        $this->actingAs($user);

        $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
        $this->assertSame('member', trim(Blade::render("@is('custom-team') member @endis")));
        $this->assertSame('', trim(Blade::render("@is('custom') member @endis")));
        $this->assertSame('', trim(Blade::render("@secureAccess('custom-team') allowed @endsecureAccess")));

        $group->update(['name' => 'Renamed team']);
        $this->assertSame('member', trim(Blade::render("@is('custom-team') member @endis")));
    }

    public function test_memberships_discriminate_morph_alias_and_member_id(): void
    {
        Relation::morphMap(['test-member' => User::class]);
        try {
            $user = $this->createUser();
            $other = $this->createUser(['username' => 'other', 'email' => 'other@example.test']);
            $group = AccessGroup::where('key', 'guest')->sole();
            DB::table('secure_access_memberships')->insert([
                'group_id' => $group->id,
                'member_type' => User::class,
                'member_id' => (string) $user->getKey(),
            ]);
            $other->accessGroups()->attach($group);
            $this->actingAs($user);

            $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
            $user->accessGroups()->attach($group);
            $this->assertSame('guest', trim(Blade::render(self::BRANCHES)));
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_persisted_authenticatable_models_do_not_need_the_optional_trait(): void
    {
        $stored = $this->createUser();
        $model = new class extends Authenticatable
        {
            protected $table = 'users';
        };
        $user = $model->newFromBuilder($stored->getAttributes());
        $this->assertFalse(method_exists($user, 'accessGroups'));
        $group = AccessGroup::where('key', 'guest')->sole();
        DB::table('secure_access_memberships')->insert([
            'group_id' => $group->id,
            'member_type' => $user->getMorphClass(),
            'member_id' => (string) $user->getKey(),
        ]);
        $this->actingAs($user);

        $this->assertSame('guest', trim(Blade::render(self::BRANCHES)));
    }

    public function test_unsaved_and_non_eloquent_users_are_safely_denied(): void
    {
        $stored = $this->createUser();
        $stored->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        $unsaved = new User;
        $unsaved->id = $stored->id;

        foreach ([$unsaved, new User, new GenericUser(['id' => $stored->id])] as $user) {
            $this->actingAs($user);
            $this->assertSame('other', trim(Blade::render(self::BRANCHES)));
        }
    }
}
