<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\CampAssignment;
use SecureAccess\Models\CampAudit;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\Permission;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class CampModulesTest extends TestCase
{
    private function loginRole(string $role): User
    {
        $user = $this->createUser();
        $user->accessGroups()->attach(AccessGroup::where('key', $role)->firstOrFail());
        $this->addTotp($user);
        $this->actingAs($user, config('secure-access.guard'))
            ->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getAuthIdentifier()]);

        return $user;
    }

    private function record(string $module, array $data, ?User $owner = null, ?int $camper = null): CampRecord
    {
        $record = new CampRecord;
        $record->forceFill([
            'module' => $module, 'data' => $data, 'camper_id' => $camper,
            'owner_type' => $owner?->getMorphClass(), 'owner_id' => $owner?->getKey(),
        ])->save();

        return $record;
    }

    private function url(string $action, string $module, ?CampRecord $record = null, array $extra = []): string
    {
        return route('secure-access.camp.'.$action, ['module' => $module] + ($record ? ['record' => $record->id] : []) + $extra);
    }

    public static function roles(): array
    {
        return [
            ['executive-director', 'budgets', 'health_profiles'],
            ['administrative-director', 'registrations', 'dosages'],
            ['medical-nursing', 'health_profiles', 'payroll'],
            ['behavioral-support', 'support_plans', 'health_profiles'],
            ['accessibility-adaptive', 'adaptive_equipment', 'billing'],
            ['program-directors', 'activity_schedules', 'health_profiles'],
            ['counselors-dsps', 'daily_logs', 'health_profiles'],
            ['facilities-maintenance', 'work_orders', 'health_profiles'],
            ['housekeeping', 'supply_requests', 'health_profiles'],
            ['catering-dietary', 'menus', 'health_profiles'],
            ['finance-accounting', 'billing', 'health_profiles'],
            ['guest', 'bookings', 'health_profiles'],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_has_its_operational_module_and_cannot_enter_an_unrelated_domain(string $role, string $allowed, string $denied): void
    {
        $user = $this->loginRole($role);
        $this->get($this->url('index', $allowed))->assertOk();
        $this->get($this->url('index', $denied))->assertForbidden();
        $this->assertArrayHasKey($allowed, Modules::visible($user));
        $this->assertArrayNotHasKey($denied, Modules::visible($user));
    }

    public function test_own_filters_apply_before_pagination_and_cannot_be_spoofed(): void
    {
        $user = $this->loginRole('guest');
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.test']);
        $mine = $this->record('bookings', ['title' => 'My booking'], $user);
        $theirs = $this->record('bookings', ['title' => 'Private booking'], $other);
        $morph = $this->record('bookings', ['title' => 'Different identity type'], $user);
        $morph->forceFill(['owner_type' => 'another-user-model'])->save();
        $this->get($this->url('index', 'bookings').'?owner_id='.$other->id)
            ->assertOk()->assertDontSee('Private booking')->assertDontSee('Different identity type')
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $mine->id);
        foreach ([$theirs, $morph] as $record) {
            $this->get($this->url('show', 'bookings', $record))->assertForbidden();
            $this->put($this->url('update', 'bookings', $record), [])->assertForbidden();
            $this->post($this->url('action', 'bookings', $record, ['action' => 'cancel']))->assertForbidden();
        }
        $this->put($this->url('update', 'bookings', $mine), [
            'title' => 'Changed', 'arrival_date' => '2026-07-01', 'departure_date' => '2026-07-02',
            'owner_id' => $other->id, 'owner_type' => 'another-user-model', 'camper_id' => $theirs->id,
            'data' => ['medical_history' => 'Injected'],
        ])->assertRedirect();
        $this->assertTrue($mine->fresh()->isOwnedBy($user));
        $this->assertNull($mine->fresh()->camper_id);
        $this->assertArrayNotHasKey('medical_history', $mine->fresh()->data);
    }

    public function test_assigned_access_uses_trusted_camper_assignments_and_create_checks_them(): void
    {
        $user = $this->loginRole('counselors-dsps');
        $a = $this->record('registrations', ['name' => 'Camper A']);
        $a->forceFill(['camper_id' => $a->id])->save();
        $b = $this->record('registrations', ['name' => 'Camper B']);
        $b->forceFill(['camper_id' => $b->id])->save();
        $assignment = new CampAssignment;
        $assignment->forceFill(['user_type' => $user->getMorphClass(), 'user_id' => $user->id, 'camper_id' => $a->id])->save();
        $mine = $this->record('care_plans', ['title' => 'Assigned care', 'instructions' => 'Trusted instruction'], camper: $a->id);
        $theirs = $this->record('care_plans', ['title' => 'Other care', 'instructions' => 'Private instruction'], camper: $b->id);
        $this->get($this->url('index', 'care_plans').'?camper_id='.$b->id)->assertOk()
            ->assertDontSee('Other care')->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $mine->id);
        $this->get($this->url('show', 'care_plans', $theirs))->assertForbidden();
        $this->get($this->url('show', 'care_plans', $mine))->assertOk();
        $payload = ['title' => 'Daily observation', 'description' => 'Attended activities'];
        $this->post($this->url('store', 'daily_logs'), $payload + ['camper_id' => $b->id])->assertForbidden();
        $this->postJson($this->url('store', 'daily_logs'), $payload)->assertUnprocessable()->assertJsonValidationErrors('camper_id');
        $this->post($this->url('store', 'daily_logs'), $payload + ['camper_id' => $a->id])->assertRedirect();
        $this->assertSame($a->id, CampRecord::where('module', 'daily_logs')->sole()->camper_id);
        $assignment->delete();
        $this->get($this->url('show', 'care_plans', $mine))->assertForbidden();
    }

    public static function projections(): array
    {
        return [
            ['behavioral-support', 'behavior_health_context', ['title' => 'Context', 'support_context' => 'Relevant support context']],
            ['program-directors', 'safety_alerts', ['title' => 'Context', 'alert' => 'Safety only', 'response' => 'Call nurse']],
            ['catering-dietary', 'dietary_restrictions', ['title' => 'Context', 'allergens' => 'Peanuts', 'dietary_instructions' => 'Avoid peanuts']],
        ];
    }

    #[DataProvider('projections')]
    public function test_read_only_contexts_never_expose_raw_health_fields(string $role, string $module, array $data): void
    {
        $this->loginRole($role);
        $record = $this->record($module, $data + ['medical_history' => 'Secret clinical diagnosis']);
        $this->get($this->url('index', $module))->assertOk()->assertDontSee('Secret clinical diagnosis')
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && ! array_key_exists('medical_history', $records->first()->data));
        $this->get($this->url('show', $module, $record))->assertOk()->assertDontSee('Secret clinical diagnosis');
        $this->get($this->url('edit', $module, $record))->assertForbidden();
        $this->put($this->url('update', $module, $record), $data)->assertForbidden();
        $this->delete($this->url('destroy', $module, $record))->assertForbidden();
        $this->post($this->url('store', $module), $data)->assertForbidden();
    }

    public function test_work_order_assignment_and_status_updates_are_validated_and_audited(): void
    {
        $user = $this->loginRole('facilities-maintenance');
        $order = $this->record('work_orders', ['title' => 'Repair', 'location' => 'Cabin A', 'description' => 'Door', 'status' => 'open']);
        $this->postJson($this->url('action', 'work_orders', $order, ['action' => 'assign']), ['assignee_id' => '999999'])->assertUnprocessable();
        $this->post($this->url('action', 'work_orders', $order, ['action' => 'assign']), ['assignee_id' => (string) $user->id])->assertRedirect();
        $this->assertSame((string) $user->id, $order->fresh()->data['assignee_id']);
        $payload = ['title' => 'Repair', 'location' => 'Cabin A', 'description' => 'Door'];
        $this->putJson($this->url('update', 'work_orders', $order), $payload + ['status' => 'arbitrary'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->put($this->url('update', 'work_orders', $order), [
            'title' => 'Unauthorized replacement', 'location' => 'Another cabin',
            'description' => 'Injected description', 'status' => 'completed',
        ])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->data['status']);
        $this->assertSame('Repair', $order->fresh()->data['title']);
        $this->assertSame('Cabin A', $order->fresh()->data['location']);
        $this->assertSame('Door', $order->fresh()->data['description']);
        $this->assertDatabaseHas('secure_access_camp_audits', ['module' => 'work_orders', 'action' => 'assign', 'record_id' => $order->id]);
        $this->post($this->url('store', 'work_orders'), $payload)->assertForbidden();
    }

    public function test_approval_and_acknowledgement_are_separate_exact_grants(): void
    {
        $user = $this->loginRole('finance-accounting');
        $card = $this->record('time_cards', ['title' => 'Week', 'hours' => 40, 'week_start' => '2026-07-01']);
        $this->post($this->url('action', 'time_cards', $card, ['action' => 'approve']))->assertRedirect();
        $this->assertSame('approved', $card->fresh()->data['status']);
        $this->put($this->url('update', 'time_cards', $card), [])->assertForbidden();
        $user->accessGroups()->sync([AccessGroup::where('key', 'facilities-maintenance')->sole()->id]);
        $alert = $this->record('emergency_alerts', ['title' => 'Storm', 'description' => 'Shelter']);
        $this->post($this->url('action', 'emergency_alerts', $alert, ['action' => 'acknowledge']))->assertRedirect();
        $this->assertContains($user->getMorphClass().':'.$user->id, $alert->fresh()->data['acknowledged_by']);
        $this->post($this->url('action', 'time_cards', $card, ['action' => 'approve']))->assertForbidden();
    }

    public function test_data_is_encrypted_and_audits_contain_metadata_only(): void
    {
        $user = $this->loginRole('medical-nursing');
        $payload = ['title' => 'Health', 'medical_history' => 'Private clinical history', 'medications' => 'Medication A', 'allergies' => 'Pollen'];
        $this->post($this->url('store', 'health_profiles'), $payload)->assertRedirect();
        $record = CampRecord::where('module', 'health_profiles')->sole();
        $raw = DB::table('secure_access_camp_records')->where('id', $record->id)->value('data');
        $this->assertStringNotContainsString('Private clinical history', $raw);
        $this->assertSame('Private clinical history', $record->data['medical_history']);
        $this->get($this->url('show', 'health_profiles', $record))->assertOk();
        $audit = CampAudit::where('action', 'read')->where('record_id', $record->id)->sole();
        $this->assertSame((string) $user->id, $audit->actor_id);
        $this->assertArrayNotHasKey('data', $audit->getAttributes());
        $this->assertStringNotContainsString('Private clinical history', $audit->toJson());
    }

    public function test_failed_auditing_rolls_back_sensitive_writes(): void
    {
        $this->loginRole('medical-nursing');
        CampAudit::creating(fn () => throw new \RuntimeException('Audit storage unavailable'));
        try {
            $this->post($this->url('store', 'health_profiles'), ['title' => 'Health', 'medical_history' => 'Private history'])->assertStatus(500);
            $this->assertDatabaseMissing('secure_access_camp_records', ['module' => 'health_profiles']);
        } finally {
            CampAudit::flushEventListeners();
        }
    }

    public function test_aggregate_and_exports_are_real_counts_without_record_payloads(): void
    {
        $user = $this->loginRole('administrative-director');
        $record = $this->record('registrations', ['name' => 'Private camper identity']);
        $this->get($this->url('index', 'compliance'))->assertOk()->assertSee('registrations')->assertDontSee('Private camper identity');
        $this->get($this->url('export', 'administrative_reports'))->assertOk()->assertDownload('administrative_reports.csv');
        $this->assertDatabaseHas('secure_access_camp_audits', ['module' => 'administrative_reports', 'action' => 'export']);
        $this->post($this->url('store', 'compliance'), [])->assertForbidden();
        $this->get($this->url('show', 'compliance', $record))->assertForbidden();
        $this->get($this->url('export', 'financial_reports'))->assertForbidden();
        $user->accessGroups()->sync([AccessGroup::where('key', 'executive-director')->sole()->id]);
        $this->get($this->url('index', 'campers'))->assertOk()->assertDontSee('Private camper identity');
        $this->get($this->url('index', 'audit'))->assertOk()->assertDontSee('Private camper identity');
    }

    public function test_public_access_requires_publication_and_exact_grants_do_not_imply_crud(): void
    {
        $user = $this->loginRole('guest');
        $public = $this->record('activities', ['title' => 'Published activity', 'description' => 'Safe']);
        $public->forceFill(['is_public' => true])->save();
        $private = $this->record('activities', ['title' => 'Unpublished activity']);
        $this->get($this->url('index', 'activities'))->assertOk()->assertDontSee('Unpublished activity')
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $public->id);
        $this->get($this->url('show', 'activities', $private))->assertForbidden();
        $this->post($this->url('action', 'activities', $private, ['action' => 'commit']))->assertNotFound();
        $this->assertNull(Modules::permission($user, 'activities', 'delete', $public));
        $guest = AccessGroup::where('key', 'guest')->sole();
        $guest->permissions()->detach(Permission::where('key', 'bookings.update_own')->sole()->id);
        $booking = $this->record('bookings', ['title' => 'Own booking'], $user);
        $this->assertNotNull(Modules::permission($user, 'bookings', 'read', $booking));
        $this->assertNull(Modules::permission($user, 'bookings', 'update', $booking));
    }

    public function test_mismatched_database_scope_cannot_widen_collection_queries(): void
    {
        $user = $this->loginRole('medical-nursing');
        Permission::where('key', 'health_profiles.read_all')->update(['scope' => 'own']);
        $other = $this->record('health_profiles', ['title' => 'Other patient', 'medical_history' => 'Private']);
        $mine = $this->record('health_profiles', ['title' => 'Own patient', 'medical_history' => 'Owned context'], $user);
        $this->assertSame('health_profiles.read_all', Modules::permission($user, 'health_profiles', 'read'));
        $this->get($this->url('show', 'health_profiles', $other))->assertForbidden();
        $this->get($this->url('index', 'health_profiles'))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $mine->id);
    }

    public function test_booking_dates_and_required_fields_are_validated_without_creating_records(): void
    {
        $this->loginRole('guest');
        $this->get($this->url('create', 'bookings'))->assertOk();
        $this->postJson($this->url('store', 'bookings'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'arrival_date', 'departure_date']);
        $this->postJson($this->url('store', 'bookings'), [
            'title' => 'Invalid interval', 'arrival_date' => '2026-07-02', 'departure_date' => '2026-07-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('departure_date');
        $this->postJson($this->url('store', 'bookings'), [
            'title' => 'Invalid date', 'arrival_date' => 'not-a-date', 'departure_date' => '2026-07-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('arrival_date');
        $this->assertDatabaseMissing('secure_access_camp_records', ['module' => 'bookings']);
        $this->get($this->url('index', 'unknown_module'))->assertNotFound();
    }

    public function test_menus_use_validated_meal_dates_and_periods(): void
    {
        $this->loginRole('catering-dietary');
        $payload = ['name' => 'Lunch menu', 'date' => '2026-07-01', 'ingredients' => 'Rice and beans', 'allergens' => 'None'];
        $this->postJson($this->url('store', 'menus'), $payload + ['period' => 'midnight'])->assertUnprocessable()
            ->assertJsonValidationErrors('period');
        $this->post($this->url('store', 'menus'), $payload + ['period' => 'lunch'])->assertRedirect();
        $this->assertSame('lunch', CampRecord::where('module', 'menus')->sole()->data['period']);
    }

    public function test_cru_modules_never_receive_delete_grants(): void
    {
        $matrix = [
            'executive-director' => ['revenue', 'budgets', 'financial_approvals'],
            'administrative-director' => ['rosters', 'cabins', 'policies', 'onboarding', 'registrations', 'waivers', 'consents', 'households'],
            'medical-nursing' => ['health_profiles', 'clinical_protocols', 'clinical_incidents'],
            'behavioral-support' => ['behavior_logs'],
            'accessibility-adaptive' => ['facility_audits'],
            'program-directors' => ['activity_adaptations'],
            'catering-dietary' => ['menus', 'ingredients', 'allergen_notices'],
        ];
        $user = $this->loginRole('guest');
        foreach ($matrix as $role => $modules) {
            $group = AccessGroup::where('key', $role)->sole();
            $user->accessGroups()->sync([$group->id]);
            foreach ($modules as $module) {
                $this->assertSame(['create', 'read', 'update'], array_values(array_intersect(
                    ['create', 'read', 'update'], Modules::get($module)['permissions'][$role]
                )));
                $this->assertFalse($group->permissions()->where('key', $module.'.delete_all')->exists(), $role.' '.$module);
                $this->assertNull(Modules::permission($user, $module, 'delete'));
                $record = $this->record($module, ['title' => 'Retained record'], $user);
                $this->delete($this->url('destroy', $module, $record))->assertForbidden();
                $this->assertModelExists($record);
            }
        }
    }

    public function test_medical_staff_can_write_narrow_contexts_without_exposing_health_payloads(): void
    {
        $this->loginRole('medical-nursing');
        $camper = $this->record('registrations', ['name' => 'Camper']);
        $contexts = [
            'care_plans' => ['title' => 'Care', 'instructions' => 'Assist transfers'],
            'allergy_alerts' => ['title' => 'Allergy', 'alert' => 'Peanuts', 'response' => 'Call nurse'],
            'safety_alerts' => ['title' => 'Safety', 'alert' => 'No diving', 'response' => 'Use shallow pool'],
            'behavior_health_context' => ['title' => 'Context', 'support_context' => 'Quiet space'],
        ];
        foreach ($contexts as $module => $data) {
            $this->post($this->url('store', $module), $data + [
                'camper_id' => $camper->id, 'medical_history' => 'Excluded clinical diagnosis',
            ])->assertRedirect();
            $record = CampRecord::where('module', $module)->sole();
            $this->assertSame($camper->id, $record->camper_id);
            $this->assertArrayNotHasKey('medical_history', $record->data);
        }
    }

    public function test_dietary_needs_generate_a_narrow_catering_projection(): void
    {
        $user = $this->loginRole('guest');
        $this->post($this->url('store', 'dietary_needs'), [
            'title' => 'My dietary needs', 'allergens' => 'Peanuts', 'dietary_instructions' => 'Avoid peanuts',
            'medical_history' => 'Excluded diagnosis',
        ])->assertRedirect();
        $projection = CampRecord::where('module', 'dietary_restrictions')->sole();
        $this->assertTrue($projection->isOwnedBy($user));
        $this->assertSame('Peanuts', $projection->data['allergens']);
        $this->assertSame(['title', 'allergens', 'dietary_instructions'], array_keys($projection->data));
        $this->assertStringNotContainsString('Excluded diagnosis', json_encode($projection->data));
    }

    public function test_only_administrators_can_assign_existing_counselors_to_real_campers(): void
    {
        $admin = $this->loginRole('administrative-director');
        $counselor = $this->createUser(['username' => 'counselor', 'email' => 'counselor@example.test']);
        $counselor->accessGroups()->attach(AccessGroup::where('key', 'counselors-dsps')->sole());
        $camper = $this->record('registrations', ['name' => 'Camper']);
        $roster = $this->record('rosters', ['title' => 'Cabin roster', 'description' => 'Staff allocation'], $admin);
        $url = $this->url('action', 'rosters', $roster, ['action' => 'assign']);
        $this->postJson($url, ['assignee_id' => $admin->id, 'camper_id' => $camper->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->post($url, ['assignee_id' => $counselor->id, 'camper_id' => $camper->id])->assertRedirect();
        $this->post($url, ['assignee_id' => $counselor->id, 'camper_id' => $camper->id])->assertRedirect();
        $this->assertSame(1, CampAssignment::where('user_type', $counselor->getMorphClass())
            ->where('user_id', (string) $counselor->id)->where('camper_id', $camper->id)->count());
        $admin->accessGroups()->sync([AccessGroup::where('key', 'counselors-dsps')->sole()->id]);
        $this->post($url, ['assignee_id' => $admin->id, 'camper_id' => $camper->id])->assertForbidden();
    }

    public function test_special_meal_form_booleans_are_stored_as_actual_booleans(): void
    {
        $user = $this->loginRole('catering-dietary');
        $payload = ['name' => 'Safe meal', 'date' => '2026-07-01', 'period' => 'lunch', 'allergens' => 'None'];
        $meal = $this->record('special_meals', $payload + ['incompatible' => false], $user);
        foreach (['1' => true, '0' => false] as $submitted => $expected) {
            $this->put($this->url('update', 'special_meals', $meal), $payload + ['incompatible' => (string) $submitted])->assertRedirect();
            $this->assertSame($expected, $meal->fresh()->data['incompatible']);
        }
    }

    public function test_payment_requests_remain_pending_without_claiming_settlement(): void
    {
        $user = $this->loginRole('guest');
        $mine = $this->record('booking_balances', ['title' => 'Balance', 'amount' => 100], $user);
        $other = $this->record('booking_balances', ['title' => 'Other balance', 'amount' => 200]);
        $this->post($this->url('action', 'booking_balances', $other, ['action' => 'pay']))->assertForbidden();
        $this->post($this->url('action', 'booking_balances', $mine, ['action' => 'pay']), [
            'status' => 'paid', 'amount' => 0, 'payment_reference' => 'untrusted',
        ])->assertRedirect();
        $this->assertSame('pending_payment', $mine->fresh()->data['status']);
        $this->assertSame(100, $mine->fresh()->data['amount']);
        $this->assertArrayNotHasKey('payment_reference', $mine->fresh()->data);
        $this->assertSame(0, CampRecord::where('module', 'payments')->count());
        $this->assertDatabaseHas('secure_access_camp_audits', ['module' => 'booking_balances', 'action' => 'pay', 'record_id' => $mine->id]);
    }

    public function test_audit_action_grants_open_read_only_aggregate_reports_without_read_grants(): void
    {
        $user = $this->loginRole('executive-director');
        foreach (['executive-director' => 'audit', 'administrative-director' => 'compliance'] as $role => $module) {
            $group = AccessGroup::where('key', $role)->sole();
            $user->accessGroups()->sync([$group->id]);
            $group->permissions()->detach(Permission::where('key', $module.'.read_all')->value('id'));
            $this->assertSame($module.'.audit_all', Modules::permission($user, $module, 'audit'));
            $this->get($this->url('index', $module))->assertOk()->assertViewHas('permissions', fn ($permissions) =>
                isset($permissions['audit']) && ! isset($permissions['read']));
            $this->post($this->url('store', $module), [])->assertForbidden();
            $this->assertDatabaseHas('secure_access_camp_audits', ['module' => $module, 'action' => 'read']);
        }
    }

    public function test_dosage_administration_requires_a_valid_status_and_recorded_timestamp(): void
    {
        $this->loginRole('medical-nursing');
        $camper = $this->record('registrations', ['name' => 'Camper']);
        $payload = ['medication' => 'Medication A', 'dose' => '5 mg', 'scheduled_at' => '2026-07-01 09:00:00', 'instructions' => 'After breakfast', 'camper_id' => $camper->id];
        $this->postJson($this->url('store', 'dosages'), $payload + ['status' => 'administered'])
            ->assertUnprocessable()->assertJsonValidationErrors('administered_at');
        $this->postJson($this->url('store', 'dosages'), $payload + ['status' => 'unrecognized'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->post($this->url('store', 'dosages'), $payload + [
            'status' => 'administered', 'administered_at' => '2026-07-01 09:01:00',
        ])->assertRedirect();
        $dosage = CampRecord::where('module', 'dosages')->sole();
        $this->assertSame($camper->id, $dosage->camper_id);
        $this->assertSame('administered', $dosage->data['status']);
        $this->assertSame('2026-07-01 09:01:00', $dosage->data['administered_at']);
    }

    public function test_accommodations_preserve_mobility_and_sensory_requirements_without_medical_payload(): void
    {
        $user = $this->loginRole('guest');
        $this->post($this->url('store', 'accommodations'), [
            'title' => 'Access needs', 'accessibility_requirements' => 'Step-free entry', 'equipment' => 'Shower chair',
            'cabin' => 'Accessible cabin', 'mobility_requirements' => 'Wheelchair turning space',
            'sensory_requirements' => 'Quiet room', 'medical_history' => 'Excluded diagnosis',
        ])->assertRedirect();
        $record = CampRecord::where('module', 'accommodations')->sole();
        $this->assertTrue($record->isOwnedBy($user));
        $this->assertSame('Wheelchair turning space', $record->data['mobility_requirements']);
        $this->assertSame('Quiet room', $record->data['sensory_requirements']);
        $this->assertArrayNotHasKey('medical_history', $record->data);
    }
}
