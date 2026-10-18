<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\SecureAccess;
use SecureAccess\Services\NurseTeamService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class NurseTeamTest extends TestCase
{
    private function user(string $name, ?string $role = null, ?string $group = null): User
    {
        $user = $this->createUser([
            'name' => $name, 'username' => $name, 'email' => $name.'@example.com', 'registration_role' => $role,
        ]);
        if ($group) {
            $user->accessGroups()->attach(AccessGroup::where('key', $group)->sole());
        }

        return $user;
    }

    private function login(User $user): void
    {
        $this->addTotp($user);
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier(),
        ]);
    }

    private function payload(User $adult, User $nurse): array
    {
        return ['self_advocate_id' => (string) $adult->id, 'nurse_id' => (string) $nurse->id];
    }

    public function test_only_current_exact_directors_can_assign_and_read_the_safe_workspace(): void
    {
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nurse', null, 'medical-nursing');
        $this->get(route('secure-access.nursing.index'))->assertRedirect(route('secure-access.login'));
        $impostor = $this->user('impostor', 'professional', 'executive-director');
        AccessGroup::create(['key' => 'lookalike', 'name' => 'Administrative Director']);
        $impostor->accessGroups()->attach(AccessGroup::where('key', 'lookalike')->sole());
        $this->login($impostor);
        $this->get(route('secure-access.nursing.index'))->assertForbidden();
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $nurse))->assertForbidden();
        $this->assertDatabaseCount('secure_access_team_members', 0);

        $director = $this->user('director', null, 'administrative-director');
        $this->actingAs($director)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get(route('secure-access.nursing.index'))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $nurse))
            ->assertRedirect(route('secure-access.login'));
        $this->login($director);
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $nurse))
            ->assertRedirect(route('secure-access.nursing.index'));
        $relationship = TeamMember::sole();
        $this->assertSame('nurse', $relationship->team_member_role);
        $this->assertSame((string) $director->id, $relationship->nurse_assigned_by);
        $this->assertNotNull($relationship->nurse_assigned_at);
        $this->assertNull($nurse->refresh()->registration_role);
        $this->assertSame('self_advocate', $adult->refresh()->registration_role);
        $this->assertSame(['medical-nursing'], $nurse->accessGroups()->pluck('key')->all());
        $this->assertSame(0, $adult->accessGroups()->count());
        $this->assertDatabaseCount('secure_access_team_authority_documents', 0);
        $response = $this->get(route('secure-access.nursing.index'))->assertOk()
            ->assertSee('adult')->assertSee('nurse')->assertDontSee('impostor')
            ->assertDontSee('adult@example.com')->assertDontSee('nurse@example.com')
            ->assertSee('Removing this nurse ends the entire website team relationship')
            ->assertSee('This does not terminate legal authority.')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $director->accessGroups()->detach();
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $nurse))->assertForbidden();
        $this->delete(route('secure-access.nursing.destroy', $relationship))->assertForbidden();
    }

    public function test_assignment_rejects_non_nurses_wrong_morph_memberships_and_non_adults(): void
    {
        $this->login($this->user('director', null, 'administrative-director'));
        $adult = $this->user('adult', 'self_advocate');
        $lookalike = $this->user('notnurse', 'professional', 'behavioral-support');
        $fakeGroup = AccessGroup::create(['key' => 'nursing-lookalike', 'name' => 'Medical / Nursing']);
        $lookalike->accessGroups()->attach($fakeGroup);
        DB::table('secure_access_memberships')->insert([
            'member_type' => 'other-model', 'member_id' => (string) $lookalike->id,
            'group_id' => AccessGroup::where('key', 'medical-nursing')->value('id'),
        ]);
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $lookalike))
            ->assertSessionHasErrors('nurse_id');
        $nurse = $this->user('nurse', null, 'medical-nursing');
        $this->post(route('secure-access.nursing.store'), $this->payload($lookalike, $nurse))
            ->assertSessionHasErrors('self_advocate_id');
        $this->post(route('secure-access.nursing.store'), [
            'self_advocate_id' => (string) $adult->id, 'nurse_id' => '999999',
        ])->assertSessionHasErrors('nurse_id');
        $nurse->accessGroups()->detach();
        $this->post(route('secure-access.nursing.store'), $this->payload($adult, $nurse))
            ->assertSessionHasErrors('nurse_id');
        $this->assertDatabaseCount('secure_access_team_members', 0);
    }

    public function test_adult_team_page_labels_assigned_staff_as_nurse_instead_of_loved_one(): void
    {
        $director = $this->user('director', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nursingcolleague', null, 'medical-nursing');
        app(NurseTeamService::class)->assign($director, (string) $adult->id, (string) $nurse->id);
        $this->login($adult);
        $this->get(route('secure-access.team.index'))->assertOk()
            ->assertSee('Nurse: nursingcolleague')->assertDontSee('Loved one: nursingcolleague');
        $this->assertNull($nurse->refresh()->registration_role);
    }

    public function test_duplicate_active_voluntary_membership_preserves_role_and_new_director_can_assume_contact(): void
    {
        $director = $this->user('director', null, 'administrative-director');
        $otherDirector = $this->user('otherdirector', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nurse', 'professional', 'medical-nursing');
        $relationship = TeamMember::create([
            'self_advocate_id' => (string) $adult->id, 'team_member_id' => (string) $nurse->id,
            'team_member_role' => 'professional',
        ]);
        $service = app(NurseTeamService::class);
        $service->assign($director, (string) $adult->id, (string) $nurse->id);
        $service->assign($director, (string) $adult->id, (string) $nurse->id);
        $this->assertDatabaseCount('secure_access_team_members', 1);
        $this->assertDatabaseCount('secure_access_nursing_assignment_audits', 1);
        $this->assertSame('professional', $relationship->refresh()->team_member_role);
        $service->assign($otherDirector, (string) $adult->id, (string) $nurse->id);
        $this->assertSame((string) $otherDirector->id, $relationship->refresh()->nurse_assigned_by);
        $this->assertSame('professional', $nurse->refresh()->registration_role);
        $this->assertDatabaseCount('secure_access_nursing_assignment_audits', 2);
    }

    public function test_ended_voluntary_relationship_reactivates_as_nurse_without_touching_revoked_documents(): void
    {
        $director = $this->user('director', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nurse', 'family_caregiver', 'medical-nursing');
        $relationship = TeamMember::create([
            'self_advocate_id' => (string) $adult->id, 'team_member_id' => (string) $nurse->id,
            'team_member_role' => 'family_caregiver', 'ended_at' => now()->subDay(),
        ]);
        $document = TeamAuthorityDocument::create([
            'team_member_id' => $relationship->id, 'type' => 'guardianship',
            'path' => 'retained.pdf', 'file_name' => 'retained.pdf',
            'status' => 'revoked', 'submitted_by' => (string) $nurse->id,
            'termination_basis' => 'relationship_ended',
        ]);
        app(NurseTeamService::class)->assign($director, (string) $adult->id, (string) $nurse->id);
        $this->assertNull($relationship->refresh()->ended_at);
        $this->assertSame('nurse', $relationship->team_member_role);
        $this->assertSame('family_caregiver', $nurse->refresh()->registration_role);
        $this->assertSame('revoked', $document->refresh()->status);
        $this->assertSame('relationship_ended', $document->termination_basis);
        $this->assertDatabaseCount('secure_access_team_members', 1);
    }

    public function test_removal_soft_ends_retains_evidence_and_history_and_reassignment_never_restores_authority(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('authority/retained.pdf', 'retained evidence');
        $director = $this->user('director', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nurse', 'professional', 'medical-nursing');
        $service = app(NurseTeamService::class);
        TeamMember::create([
            'self_advocate_id' => (string) $adult->id, 'team_member_id' => (string) $nurse->id,
            'team_member_role' => 'professional',
        ]);
        $relationship = $service->assign($director, (string) $adult->id, (string) $nurse->id);
        $document = TeamAuthorityDocument::create([
            'team_member_id' => $relationship->id, 'type' => 'power_of_attorney',
            'path' => 'authority/retained.pdf', 'file_name' => 'retained.pdf',
            'status' => 'approved', 'submitted_by' => (string) $nurse->id,
            'approved_scopes' => ['health_profiles.read'], 'effective_at' => now()->subDay(),
        ]);
        $this->login($director);
        $this->delete(route('secure-access.nursing.destroy', $relationship))->assertRedirect();
        $this->assertNotNull($relationship->refresh()->ended_at);
        $this->assertSame((string) $director->id, $relationship->nurse_assigned_by);
        $this->assertSame('suspended', $document->refresh()->status);
        $this->assertSame('relationship_ended', $document->termination_basis);
        Storage::disk('local')->assertExists('authority/retained.pdf');
        $this->assertDatabaseHas('secure_access_authority_document_audits', [
            'team_member_id' => $relationship->id, 'actor_id' => (string) $director->id,
            'action' => 'relationship_ended',
        ]);
        $this->delete(route('secure-access.nursing.destroy', $relationship))->assertRedirect();
        $service->assign($director, (string) $adult->id, (string) $nurse->id);
        $this->assertDatabaseCount('secure_access_team_members', 1);
        $this->assertNull($relationship->refresh()->ended_at);
        $this->assertSame('nurse', $relationship->team_member_role);
        $this->assertSame('suspended', $document->refresh()->status);
        $this->assertDatabaseCount('secure_access_nursing_assignment_audits', 3);
        $this->assertEqualsCanonicalizing(['id', 'actor_id', 'team_member_id', 'action', 'created_at'],
            array_keys((array) DB::table('secure_access_nursing_assignment_audits')->first()));
    }

    public function test_removal_rejects_other_staff_and_unassigned_relationships(): void
    {
        $director = $this->user('director', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $nurse = $this->user('nurse', null, 'medical-nursing');
        $relationship = app(NurseTeamService::class)->assign($director, (string) $adult->id, (string) $nurse->id);
        $this->login($nurse);
        $this->delete(route('secure-access.nursing.destroy', $relationship))->assertForbidden();
        $this->assertNull($relationship->refresh()->ended_at);
        $volunteer = $this->user('volunteer', 'professional');
        $unassigned = TeamMember::create([
            'self_advocate_id' => (string) $adult->id, 'team_member_id' => (string) $volunteer->id,
            'team_member_role' => 'professional',
        ]);
        $this->login($director);
        $this->delete(route('secure-access.nursing.destroy', $unassigned))->assertNotFound();
        $this->delete('/dashboard/nursing/not-numeric')->assertNotFound();
        $this->assertNull($unassigned->refresh()->ended_at);
    }

    public function test_reassignment_has_one_durable_current_department_assignment_and_old_removal_cannot_clear_it(): void
    {
        $director = $this->user('director', null, 'administrative-director');
        $adult = $this->user('adult', 'self_advocate');
        $first = $this->user('first', null, 'medical-nursing');
        $second = $this->user('second', null, 'medical-nursing');
        $service = app(NurseTeamService::class);
        $previous = $service->assign($director, (string) $adult->id, (string) $first->id);
        $service->assign($director, (string) $adult->id, (string) $second->id);
        $assignment = DepartmentAssignment::sole();
        $this->assertSame((string) $second->id, $assignment->assignee_id);
        $this->assertSame(2, $assignment->version);
        $this->login($director);
        $response = $this->get(route('secure-access.nursing.index'))->assertOk();
        $this->assertCount(1, $response->viewData('assignments'));
        $this->assertSame((string) $second->id, $response->viewData('assignments')->first()->team_member_id);
        $service->remove($director, $previous);
        $this->assertSame((string) $second->id, $assignment->refresh()->assignee_id);
    }
}
