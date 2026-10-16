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
        $this->put($this->url('update', 'work_orders', $order), $payload + ['status' => 'completed'])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->data['status']);
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
        $this->post($this->url('action', 'activities', $private, ['action' => 'commit']))->assertForbidden();
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
        $this->assertNull(Modules::permission($user, 'health_profiles', 'read'));
        $this->get($this->url('show', 'health_profiles', $other))->assertForbidden();
        $this->get($this->url('index', 'health_profiles'))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 0);
    }
}
