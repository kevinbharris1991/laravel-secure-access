<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\CampAudit;
use SecureAccess\Models\CampRecord;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class GuestPortalTest extends TestCase
{
    private function loginGuest(): User
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', 'guest')->firstOrFail());
        $this->verifyUser($user);

        return $user;
    }

    private function verifyUser(User $user): void
    {
        $this->addTotp($user);
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier()]);
    }

    private function record(string $module, array $data, ?User $owner = null, bool $public = false): CampRecord
    {
        $record = new CampRecord;
        $record->forceFill([
            'module' => $module,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner ? (string) $owner->getKey() : null,
            'is_public' => $public,
            'data' => $data,
        ])->save();

        return $record;
    }

    public function test_meals_match_only_own_substitution_for_each_date_and_period(): void
    {
        $guest = $this->loginGuest();
        foreach (['2026-07-01', '2026-07-02'] as $date) {
            foreach (['breakfast', 'lunch', 'dinner'] as $period) {
                $this->record('meal_plans', [
                    'date' => $date, 'period' => $period, 'name' => $date.' '.$period,
                    'allergens' => 'Milk',
                ], public: true);
            }
        }
        $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'breakfast', 'name' => 'Safe breakfast', 'incompatible' => true,
        ], $guest);
        $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'lunch', 'name' => 'Safe lunch', 'incompatible' => true,
        ], $guest);
        $this->record('special_meals', [
            'date' => '2026-07-02', 'period' => 'dinner', 'name' => 'Safe dinner', 'incompatible' => true,
        ], $guest);

        $response = $this->get(route('secure-access.guest.index'))->assertOk()
            ->assertSee('<s>2026-07-01 breakfast</s>', false)
            ->assertSee('<s>2026-07-01 lunch</s>', false)
            ->assertSee('<s>2026-07-02 dinner</s>', false)
            ->assertSee('Safe breakfast')->assertSee('Safe dinner');
        foreach (['2026-07-01 dinner', '2026-07-02 breakfast', '2026-07-02 lunch'] as $normal) {
            $response->assertSee($normal)->assertDontSee('<s>'.$normal.'</s>', false);
        }
        $this->assertSame(3, substr_count($response->getContent(), '<s>'));
    }

    public function test_other_guests_and_non_incompatible_substitutions_cannot_strike_meals(): void
    {
        $guest = $this->loginGuest();
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        $this->record('meal_plans', [
            'date' => '2026-07-01', 'period' => 'lunch', 'name' => 'Public lunch',
        ], public: true);
        $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'lunch', 'name' => 'Private other meal', 'incompatible' => true,
        ], $other);
        $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'lunch', 'name' => 'Compatible option', 'incompatible' => false,
        ], $guest);
        $spoof = $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'lunch', 'name' => 'Other identity type', 'incompatible' => true,
        ], $guest);
        $spoof->forceFill(['owner_type' => 'other-model'])->save();

        $this->get(route('secure-access.guest.index'))->assertOk()
            ->assertSee('Public lunch')->assertSee('Compatible option')
            ->assertDontSee('<s>', false)->assertDontSee('Private other meal')->assertDontSee('Other identity type');
    }

    public function test_only_public_meals_and_opportunities_are_visible_and_content_is_escaped(): void
    {
        $this->loginGuest();
        foreach (['meal_plans', 'activities', 'events'] as $module) {
            $this->record($module, [
                'name' => 'Hidden '.$module, 'date' => '2026-07-01', 'period' => 'dinner',
            ]);
            $this->record($module, [
                'name' => '<script>'.$module.'</script>', 'date' => '2026-07-01', 'period' => 'dinner',
            ], public: true);
        }
        $response = $this->get(route('secure-access.guest.index'))->assertOk();
        foreach (['meal_plans', 'activities', 'events'] as $module) {
            $response->assertDontSee('Hidden '.$module)->assertSee('<script>'.$module.'</script>')
                ->assertDontSee('<script>'.$module.'</script>', false);
        }
    }

    public function test_enrollment_is_idempotent_server_owned_and_only_own_commitments_are_read(): void
    {
        $guest = $this->loginGuest();
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        foreach (['activities', 'events'] as $module) {
            $activity = $this->record($module, ['name' => 'Public '.$module], public: true);
            $this->record('activity_commitments', [
                'name' => 'Other guest commitment', 'target_module' => $module, 'target_id' => $activity->id,
            ], $other);
            $url = route('secure-access.guest.commit', ['module' => $module, 'record' => $activity->id]);
            $this->post($url, [
                'owner_id' => $other->id, 'owner_type' => 'tampered', 'target_id' => 999,
            ])->assertRedirect(route('secure-access.guest.index'));
            $this->post($url)->assertRedirect(route('secure-access.guest.index'));
        }
        $commitments = CampRecord::where('module', 'activity_commitments')->where('owner_id', (string) $guest->id)->get();
        $this->assertCount(2, $commitments);
        $this->assertSame(4, CampRecord::where('module', 'activity_commitments')->count());
        foreach ($commitments as $commitment) {
            $this->assertTrue($commitment->isOwnedBy($guest));
            $this->assertNotSame(999, $commitment->data['target_id']);
        }
        $this->record('activity_commitments', [
            'name' => 'Other guest commitment', 'target_module' => 'events', 'target_id' => 999,
        ], $other);
        $this->get(route('secure-access.guest.index'))->assertOk()
            ->assertViewHas('commitments', fn ($rows) => $rows->count() === 2 && $rows->every(fn ($row) => $row->isOwnedBy($guest)))
            ->assertDontSee('Other guest commitment')->assertSee('You are enrolled');
    }

    public function test_unpublished_or_wrong_module_enrollment_is_rejected(): void
    {
        $this->loginGuest();
        $hidden = $this->record('events', ['name' => 'Hidden event']);
        $this->post(route('secure-access.guest.commit', ['module' => 'events', 'record' => $hidden->id]))->assertNotFound();
        $public = $this->record('events', ['name' => 'Visible event'], public: true);
        $this->post(route('secure-access.guest.commit', ['module' => 'activities', 'record' => $public->id]))->assertNotFound();
        $this->post(route('secure-access.guest.commit', ['module' => 'special_meals', 'record' => $public->id]))->assertNotFound();
        $this->assertSame(0, CampRecord::where('module', 'activity_commitments')->count());
    }

    public function test_generic_commit_action_cannot_bypass_publication_or_idempotency(): void
    {
        $this->loginGuest();
        $hidden = $this->record('events', ['name' => 'Hidden event']);
        $this->post(route('secure-access.camp.action', [
            'module' => 'events', 'record' => $hidden->id, 'action' => 'commit',
        ]))->assertNotFound();

        $public = $this->record('events', ['name' => 'Public event'], public: true);
        $url = route('secure-access.camp.action', [
            'module' => 'events', 'record' => $public->id, 'action' => 'commit',
        ]);
        $this->post($url)->assertRedirect(route('secure-access.guest.index'));
        $this->post($url)->assertRedirect(route('secure-access.guest.index'));
        $this->assertSame(1, CampRecord::where('module', 'activity_commitments')->count());
    }

    public function test_guest_cannot_modify_substitutions_through_generic_workflow(): void
    {
        $guest = $this->loginGuest();
        $special = $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'dinner', 'name' => 'Staff approved', 'incompatible' => true,
        ], $guest);
        $this->put(route('secure-access.camp.update', ['module' => 'special_meals', 'record' => $special->id]), [
            'name' => 'Self approved', 'incompatible' => false,
        ])->assertForbidden();
        $this->post(route('secure-access.camp.store', ['module' => 'special_meals']), [
            'name' => 'Self created', 'incompatible' => false,
        ])->assertForbidden();
        $this->delete(route('secure-access.camp.destroy', ['module' => 'special_meals', 'record' => $special->id]))
            ->assertForbidden();
        $this->assertSame('Staff approved', $special->fresh()->data['name']);
    }

    public function test_non_guests_are_forbidden_and_guest_routes_require_mfa(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $this->actingAs($user)->get(route('secure-access.guest.index'))->assertRedirect(route('secure-access.login'));
        $activity = $this->record('activities', ['name' => 'Public activity'], public: true);
        $this->post(route('secure-access.guest.commit', ['module' => 'activities', 'record' => $activity->id]))
            ->assertRedirect(route('secure-access.login'));
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);
        $this->get(route('secure-access.guest.index'))->assertForbidden();
        $this->post(route('secure-access.guest.commit', ['module' => 'activities', 'record' => $activity->id]))->assertForbidden();
        $user->accessGroups()->attach(AccessGroup::where('key', 'catering-dietary')->firstOrFail());
        $this->get(route('secure-access.guest.index'))->assertForbidden();
        $this->post(route('secure-access.guest.commit', ['module' => 'activities', 'record' => $activity->id]))->assertForbidden();
    }

    public function test_sensitive_substitution_views_are_audited_without_phi_payload(): void
    {
        $guest = $this->loginGuest();
        $this->record('special_meals', [
            'date' => '2026-07-01', 'period' => 'breakfast', 'name' => 'Sensitive substitution', 'incompatible' => true,
        ], $guest);
        $this->get(route('secure-access.guest.index'))->assertOk();
        $this->assertTrue(CampAudit::where('module', 'special_meals')->where('action', 'read')->exists());
        $this->assertStringNotContainsString('Sensitive substitution', CampAudit::all()->toJson());
    }
}
