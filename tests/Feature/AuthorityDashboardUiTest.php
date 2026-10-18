<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\TestCase;

class AuthorityDashboardUiTest extends TestCase
{
    public function test_authority_management_dashboard_controls_require_administrative_director_membership(): void
    {
        $user = $this->createUser();
        $executive = AccessGroup::where('key', 'executive-director')->firstOrFail();
        $administrative = AccessGroup::where('key', 'administrative-director')->firstOrFail();
        $user->accessGroups()->attach($executive);
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);

        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertDontSee('Review queue &amp; history', false)
            ->assertDontSee('Assign or remove a nurse');

        $user->accessGroups()->attach($administrative);
        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertSee('Review queue &amp; history', false)
            ->assertSee('Assign or remove a nurse');

        $administrative->permissions()->detach(Permission::where('key', 'authority_documents.review_all')->value('id'));
        $user->accessGroups()->detach($executive);
        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertDontSee('Review queue &amp; history', false);
    }

    public function test_lookalike_administrative_group_cannot_expose_authority_management(): void
    {
        $user = $this->createUser();
        $group = AccessGroup::create(['key' => 'lookalike-authority', 'name' => 'Administrative Director']);
        $group->permissions()->attach(Permission::where('key', 'authority_documents.review_all')->firstOrFail());
        $user->accessGroups()->attach($group);
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);

        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertDontSee('Review queue &amp; history', false)
            ->assertDontSee('Assign or remove a nurse');
    }
}
