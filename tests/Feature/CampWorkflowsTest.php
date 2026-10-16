<?php

namespace SecureAccess\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\CampAssignment;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class CampWorkflowsTest extends TestCase
{
    private function role(string $role, string $name = 'actor'): User
    {
        $user = $this->createUser(['username' => $name, 'email' => $name.'@example.test']);
        $user->accessGroups()->attach(AccessGroup::where('key', $role)->sole());
        $this->login($user);

        return $user;
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);
    }

    private function record(string $module, array $data = [], ?User $owner = null, ?int $camper = null): CampRecord
    {
        $record = new CampRecord;
        $record->forceFill([
            'module' => $module, 'data' => $data, 'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->id, 'camper_id' => $camper,
        ])->save();
        if ($module === 'registrations') {
            $record->camper_id = $record->id;
            $record->save();
        }

        return $record;
    }

    private function url(string $action, string $module, ?CampRecord $record = null, ?string $workflow = null): string
    {
        return route('secure-access.camp.'.$action, array_filter([
            'module' => $module, 'record' => $record?->id, 'action' => $workflow,
        ], fn ($value) => $value !== null));
    }

    public static function cruModules(): array
    {
        return [
            ['executive-director', 'budgets'], ['executive-director', 'financial_approvals'],
            ['administrative-director', 'registrations'], ['administrative-director', 'rosters'],
            ['medical-nursing', 'health_profiles'], ['medical-nursing', 'clinical_incidents'],
            ['behavioral-support', 'behavior_logs'], ['accessibility-adaptive', 'facility_audits'],
            ['program-directors', 'activity_adaptations'], ['catering-dietary', 'menus'],
        ];
    }

    #[DataProvider('cruModules')]
    public function test_cru_roles_cannot_delete_even_with_legacy_manage_grants(string $role, string $module): void
    {
        $this->role($role);
        $record = $this->record($module, ['title' => 'Retained']);
        $this->delete($this->url('destroy', $module, $record))->assertForbidden();
        $this->assertNotNull($record->fresh());
    }

    public function test_database_scope_narrowing_also_filters_collection_queries(): void
    {
        $user = $this->role('medical-nursing');
        $mine = $this->record('health_profiles', ['title' => 'My clinical record'], $user);
        $other = $this->record('health_profiles', ['title' => 'Unrelated private record']);
        Permission::where('key', 'health_profiles.read_all')->update(['scope' => 'own']);
        $this->get($this->url('index', 'health_profiles'))->assertOk()->assertSee('My clinical record')
            ->assertDontSee('Unrelated private record')->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->get($this->url('show', 'health_profiles', $other))->assertForbidden();
        $this->get($this->url('show', 'health_profiles', $mine))->assertOk();
    }

    public function test_database_assigned_create_scope_checks_the_proposed_camper_before_persistence(): void
    {
        $user = $this->role('medical-nursing');
        $assigned = $this->record('registrations', ['name' => 'Assigned camper']);
        $other = $this->record('registrations', ['name' => 'Other camper']);
        (new CampAssignment)->forceFill(['user_type' => $user->getMorphClass(), 'user_id' => (string) $user->id, 'camper_id' => $assigned->id])->save();
        Permission::where('key', 'health_profiles.create_all')->update(['scope' => 'assigned']);
        $data = ['title' => 'Clinical profile', 'medical_history' => 'Clinical details'];
        $this->post($this->url('store', 'health_profiles'), $data + ['camper_id' => $other->id])->assertForbidden();
        $this->postJson($this->url('store', 'health_profiles'), $data)->assertUnprocessable()->assertJsonValidationErrors('camper_id');
        $this->assertSame(0, CampRecord::where('module', 'health_profiles')->count());
        $this->post($this->url('store', 'health_profiles'), $data + ['camper_id' => $assigned->id])->assertRedirect();
    }

    public function test_staff_publishing_and_guest_disclosures_feed_dietary_review_without_clinical_notes(): void
    {
        $catering = $this->role('catering-dietary', 'catering');
        $this->post($this->url('store', 'meal_plans'), [
            'name' => 'Standard lunch', 'date' => '2027-07-01', 'period' => 'lunch', 'allergens' => 'Milk', 'is_public' => '1',
        ])->assertRedirect();
        $guest = $this->role('guest', 'guest');
        $this->post($this->url('store', 'dietary_needs'), [
            'title' => 'Diet', 'allergens' => 'Milk', 'dietary_instructions' => 'Dairy-free',
            'medical_history' => 'PRIVATE CLINICAL HISTORY',
        ])->assertRedirect();
        $special = CampRecord::where('module', 'special_meals')->sole();
        $this->assertTrue($special->isOwnedBy($guest));
        $this->login($catering);
        $this->get($this->url('index', 'dietary_restrictions'))->assertOk()->assertDontSee('PRIVATE CLINICAL HISTORY');
        $this->post($this->url('store', 'special_meals'), [])->assertForbidden();
        $this->put($this->url('update', 'special_meals', $special), [
            'name' => 'Safe lunch', 'date' => '2027-07-01', 'period' => 'lunch', 'allergens' => '', 'incompatible' => '1',
        ])->assertRedirect();
        $this->login($guest);
        $this->get(route('secure-access.guest.index'))->assertOk()->assertSee('<s>Standard lunch</s>', false)->assertSee('Safe lunch');
    }

    public function test_program_staff_can_publish_real_guest_opportunities(): void
    {
        $this->role('program-directors', 'program');
        $this->post($this->url('store', 'activities'), [
            'title' => 'Accessible canoeing', 'scheduled_at' => '2027-07-01 14:00:00',
            'location' => 'Lake', 'description' => 'Adaptive activity', 'is_public' => '1',
        ])->assertRedirect();
        $this->role('guest', 'guest');
        $this->get(route('secure-access.guest.index'))->assertOk()->assertSee('Accessible canoeing');
    }

    public function test_roster_assignments_unlock_only_the_assigned_camper(): void
    {
        $admin = $this->role('administrative-director', 'admin');
        $counselor = $this->role('counselors-dsps', 'counselor');
        $camper = $this->record('registrations', ['name' => 'Camper']);
        $plan = $this->record('care_plans', ['title' => 'Care', 'instructions' => 'Assigned care'], camper: $camper->id);
        $roster = $this->record('rosters', ['title' => 'Cabin roster', 'description' => 'Staff assignments']);
        $this->get($this->url('show', 'care_plans', $plan))->assertForbidden();
        $this->login($admin);
        $this->post($this->url('action', 'rosters', $roster, 'assign'), ['assignee_id' => $counselor->id, 'camper_id' => $camper->id])->assertRedirect();
        $this->login($counselor);
        $this->get($this->url('show', 'care_plans', $plan))->assertOk()->assertSee('Assigned care');
    }

    public function test_maintenance_incident_routing_does_not_copy_camper_data(): void
    {
        $counselor = $this->role('counselors-dsps', 'counselor');
        $camper = $this->record('registrations', ['name' => 'Private camper']);
        (new CampAssignment)->forceFill(['user_type' => $counselor->getMorphClass(), 'user_id' => (string) $counselor->id, 'camper_id' => $camper->id])->save();
        $this->post($this->url('store', 'incident_flags'), [
            'title' => 'Private camper incident', 'description' => 'PRIVATE BEHAVIOR',
            'destination' => 'maintenance', 'location' => 'Cabin ramp', 'camper_id' => $camper->id,
        ])->assertRedirect();
        $workOrder = CampRecord::where('module', 'work_orders')->sole();
        $this->assertNull($workOrder->camper_id);
        $this->role('facilities-maintenance', 'facilities');
        $this->get($this->url('show', 'work_orders', $workOrder))->assertOk()->assertSee('Cabin ramp')
            ->assertDontSee('Private camper')->assertDontSee('PRIVATE BEHAVIOR');
        $this->put($this->url('update', 'work_orders', $workOrder), ['status' => 'completed', 'description' => 'Tampered'])->assertRedirect();
        $this->assertNotSame('Tampered', $workOrder->fresh()->data['description']);
    }

    public function test_owned_balance_payment_requests_do_not_claim_settlement(): void
    {
        $finance = $this->role('finance-accounting', 'finance');
        $guest = $this->role('guest', 'guest');
        $this->login($finance);
        $this->post($this->url('store', 'booking_balances'), ['title' => 'Stay balance', 'amount' => '100', 'guest_id' => $guest->id])->assertRedirect();
        $balance = CampRecord::where('module', 'booking_balances')->sole();
        $this->login($guest);
        $this->get($this->url('show', 'booking_balances', $balance))->assertOk()->assertSee('Stay balance');
        $this->post($this->url('action', 'booking_balances', $balance, 'pay'))->assertRedirect();
        $this->assertSame('pending_payment', $balance->fresh()->data['status']);
        $this->role('guest', 'other-guest');
        $this->post($this->url('action', 'booking_balances', $balance, 'pay'))->assertForbidden();
    }

    public function test_cabin_setup_and_failed_inspections_feed_read_update_only_departments(): void
    {
        $this->role('administrative-director', 'admin');
        $this->post($this->url('store', 'cabins'), ['title' => 'Cabin setup', 'description' => 'PRIVATE INTAKE CONTEXT'])->assertRedirect();
        $this->assertSame(1, CampRecord::where('module', 'sanitation_rosters')->count());
        $this->assertSame(1, CampRecord::where('module', 'room_requirements')->count());
        $this->role('housekeeping', 'housekeeping');
        $this->get($this->url('show', 'sanitation_rosters', CampRecord::where('module', 'sanitation_rosters')->sole()))
            ->assertOk()->assertDontSee('PRIVATE INTAKE CONTEXT');
        $facilities = $this->role('facilities-maintenance', 'facilities');
        $this->post($this->url('store', 'safety_inspections'), [
            'title' => 'Ramp inspection', 'description' => 'Loose handrail', 'result' => 'failed',
        ])->assertRedirect();
        $alert = CampRecord::where('module', 'emergency_alerts')->sole();
        $this->post($this->url('action', 'emergency_alerts', $alert, 'acknowledge'))->assertRedirect();
        $second = $this->role('facilities-maintenance', 'second');
        $this->post($this->url('action', 'emergency_alerts', $alert, 'acknowledge'))->assertRedirect();
        $this->assertEqualsCanonicalizing([
            $facilities->getMorphClass().':'.$facilities->id, $second->getMorphClass().':'.$second->id,
        ], $alert->fresh()->data['acknowledged_by']);
    }

    public function test_guest_enrollment_populates_minimized_program_participation(): void
    {
        $program = $this->role('program-directors', 'program');
        $this->post($this->url('store', 'events'), [
            'title' => 'Campfire', 'scheduled_at' => '2027-07-01 18:00:00', 'location' => 'Lake',
            'description' => 'Music', 'is_public' => '1',
        ])->assertRedirect();
        $event = CampRecord::where('module', 'events')->sole();
        $this->role('guest', 'guest');
        $this->post(route('secure-access.guest.commit', ['module' => 'events', 'record' => $event->id]))->assertRedirect();
        $participation = CampRecord::where('module', 'participation')->sole();
        $this->assertSame('Campfire', $participation->data['title']);
        $this->assertArrayNotHasKey('medical_history', $participation->data);
        $this->login($program);
        $this->put($this->url('update', 'participation', $participation), [
            'title' => 'Campfire', 'description' => 'Guest activity enrollment', 'attendance' => 'present', 'low_stimulation' => '1',
        ])->assertRedirect();
    }

    public function test_compliance_reports_find_missing_camper_documents_without_exporting_names(): void
    {
        $this->role('administrative-director', 'admin');
        $camper = $this->record('registrations', ['name' => 'Private camper']);
        $this->post($this->url('store', 'waivers'), [
            'title' => 'Signed waiver', 'description' => 'Completed', 'camper_id' => $camper->id,
        ])->assertRedirect();
        $this->get($this->url('index', 'compliance'))->assertOk()->assertDontSee('Private camper')
            ->assertViewHas('records', fn ($records) => collect($records->items())->contains(fn ($row) =>
                $row->data['module'] === 'Missing consents' && $row->data['records'] === 1));
    }
}
