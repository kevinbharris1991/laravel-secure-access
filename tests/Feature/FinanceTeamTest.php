<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\SecureAccess;
use SecureAccess\Services\DepartmentAssignmentService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class FinanceTeamTest extends TestCase
{
    private function account(string $name, ?string $role = null, ?string $group = null): User
    {
        $user = $this->createUser(['username' => $name, 'email' => $name.'@example.com', 'name' => $name, 'registration_role' => $role]);
        if ($group) {
            $user->accessGroups()->attach(AccessGroup::where('key', $group)->sole());
        }

        return $user;
    }

    private function login(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);
    }

    public function test_directors_can_assign_reassign_and_remove_only_current_finance_officer_with_audit(): void
    {
        $director = $this->account('director', null, 'administrative-director');
        $ward = $this->account('ward', 'self_advocate');
        $officer = $this->account('officer', null, 'finance-accounting');
        $replacement = $this->account('replacement', null, 'finance-accounting');
        $this->login($director);
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $officer->id,
        ])->assertRedirect(route('secure-access.finance.index'));
        $assignment = DepartmentAssignment::sole();
        $this->assertSame((string) $officer->id, $assignment->assignee_id);
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $officer->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('secure_access_department_assignment_audits', 1);
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $replacement->id,
        ])->assertRedirect();
        $this->assertSame((string) $replacement->id, $assignment->refresh()->assignee_id);
        $this->assertSame(2, $assignment->version);
        $this->get(route('secure-access.finance.index'))->assertOk()->assertSee('replacement')
            ->assertDontSee('replacement@example.com')->assertHeader('cache-control', 'no-store, private');
        $this->delete(route('secure-access.finance.destroy', $assignment))->assertRedirect();
        $this->assertNull($assignment->refresh()->assignee_id);
        $this->delete(route('secure-access.finance.destroy', $assignment))->assertRedirect();
        $this->assertDatabaseCount('secure_access_department_assignments', 1);
        $this->assertSame(['assigned', 'reassigned', 'removed'], DB::table('secure_access_department_assignment_audits')->orderBy('id')->pluck('action')->all());
        $this->assertDatabaseCount('secure_access_team_members', 0);
    }

    public function test_finance_management_rejects_non_directors_wrong_department_and_wrong_membership_morph(): void
    {
        $ward = $this->account('ward', 'self_advocate');
        $officer = $this->account('officer', null, 'finance-accounting');
        $this->get(route('secure-access.finance.index'))->assertRedirect(route('secure-access.login'));
        $this->login($officer);
        $this->get(route('secure-access.finance.index'))->assertForbidden();
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $officer->id,
        ])->assertForbidden();
        $director = $this->account('director', null, 'administrative-director');
        $this->login($director);
        $nurse = $this->account('nurse', null, 'medical-nursing');
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $nurse->id,
        ])->assertSessionHasErrors('officer_id');
        DB::table('secure_access_memberships')->insert([
            'member_type' => 'wrong-model', 'member_id' => (string) $nurse->id,
            'group_id' => AccessGroup::where('key', 'finance-accounting')->value('id'),
        ]);
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $ward->id, 'officer_id' => (string) $nurse->id,
        ])->assertSessionHasErrors('officer_id');
        $this->post(route('secure-access.finance.store'), [
            'self_advocate_id' => (string) $officer->id, 'officer_id' => (string) $officer->id,
        ])->assertSessionHasErrors('self_advocate_id');
        $medical = app(DepartmentAssignmentService::class)->assign($director, (string) $ward->id, 'medical-nursing', (string) $nurse->id);
        $this->delete(route('secure-access.finance.destroy', $medical))->assertNotFound();
    }
}
