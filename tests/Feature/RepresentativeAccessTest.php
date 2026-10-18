<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\CampRecord;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class RepresentativeAccessTest extends TestCase
{
    protected User $adult;

    protected User $representative;

    protected TeamMember $relationship;

    protected TeamAuthorityDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adult = $this->createUser(['username' => 'adult', 'email' => 'adult@example.test', 'registration_role' => 'self_advocate']);
        $this->adult->accessGroups()->attach(AccessGroup::where('key', 'guest')->sole());
        $this->representative = $this->createUser(['username' => 'representative', 'email' => 'representative@example.test', 'registration_role' => 'family_caregiver']);
        $this->relationship = TeamMember::create([
            'self_advocate_id' => (string) $this->adult->id,
            'team_member_id' => (string) $this->representative->id,
            'team_member_role' => 'family_caregiver',
        ]);
        $this->document = new TeamAuthorityDocument;
        $this->document->forceFill([
            'team_member_id' => $this->relationship->id, 'type' => 'guardianship',
            'path' => 'private/original.pdf', 'file_name' => 'authority-document.pdf',
            'submitted_by' => (string) $this->representative->id, 'status' => 'approved',
            'approved_scopes' => ['bookings.create', 'bookings.read', 'bookings.update', 'bookings.cancel'],
            'effective_at' => now()->subDay(), 'expires_at' => now()->addDay(),
        ])->save();
        $this->signIn($this->representative);
    }

    protected function signIn(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id,
        ]);
    }

    protected function booking(User $owner, array $attributes = []): CampRecord
    {
        $record = new CampRecord;
        $record->forceFill($attributes + [
            'module' => 'bookings', 'owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $owner->id,
            'data' => ['title' => 'Private reservation', 'arrival_date' => '2099-01-01', 'departure_date' => '2099-01-02'],
        ])->save();

        return $record;
    }

    public function test_workspace_uses_exact_grants_and_never_changes_the_authenticated_identity(): void
    {
        $this->get(route('secure-access.representative.index'))->assertOk()->assertSee('Acting on behalf of:');
        $this->get(route('secure-access.representative.module', [$this->relationship, 'bookings']))->assertOk();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'health_disclosures']))->assertForbidden();
        $this->get(route('secure-access.camp.index', 'bookings'))->assertForbidden();
        $this->assertAuthenticatedAs($this->representative, config('secure-access.guard'));
        $this->assertSame([], $this->representative->accessGroups()->pluck('key')->all());
    }

    public function test_delegated_writes_are_owned_by_the_adult_and_audit_both_identities_without_payloads(): void
    {
        $this->post(route('secure-access.representative.store', [$this->relationship, 'bookings']), [
            'title' => 'Sensitive reservation detail', 'arrival_date' => '2099-01-01', 'departure_date' => '2099-01-02',
            'owner_id' => $this->representative->id, 'is_public' => true, 'camper_id' => 999,
        ])->assertRedirect();
        $record = CampRecord::where('module', 'bookings')->sole();
        $this->assertSame((string) $this->adult->id, $record->owner_id);
        $this->assertFalse($record->is_public);
        $this->assertNull($record->camper_id);
        $audit = \Illuminate\Support\Facades\DB::table('secure_access_representative_audits')->sole();
        $this->assertSame((string) $this->representative->id, $audit->actor_id);
        $this->assertSame((string) $this->adult->id, $audit->represented_id);
        $this->assertSame($this->document->id, $audit->document_id);
        $this->assertStringNotContainsString('Sensitive reservation detail', json_encode($audit));
    }

    public function test_unrelated_records_cannot_be_read_or_updated_even_with_an_approved_document(): void
    {
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.test']);
        $own = $this->booking($this->adult);
        $unrelated = $this->booking($other, ['data' => ['title' => 'Unrelated sensitive reservation']]);
        $this->get(route('secure-access.representative.module', [$this->relationship, 'bookings']))
            ->assertOk()->assertSee('Private reservation')->assertDontSee('Unrelated sensitive reservation');
        $data = ['title' => 'Changed', 'arrival_date' => '2099-01-03', 'departure_date' => '2099-01-04'];
        $this->put(route('secure-access.representative.update', [$this->relationship, 'bookings', $unrelated]), $data)->assertNotFound();
        $this->put(route('secure-access.representative.update', [$this->relationship, 'bookings', $own]), $data)->assertRedirect();
        $this->assertSame('Changed', $own->fresh()->data['title']);
    }

    public function test_revocation_expiration_and_future_effective_dates_are_enforced_without_a_new_login(): void
    {
        $url = route('secure-access.representative.module', [$this->relationship, 'bookings']);
        $this->get($url)->assertOk();
        foreach (['pending', 'rejected', 'needs_information', 'revoked', 'suspended', 'superseded', 'expired'] as $state) {
            $this->document->forceFill(['status' => $state])->save();
            $this->get($url)->assertForbidden();
        }
        $this->document->forceFill(['status' => 'approved', 'expires_at' => now()])->save();
        $this->get($url)->assertForbidden();
        $this->document->forceFill(['expires_at' => now()->addDay(), 'effective_at' => now()->addHour()])->save();
        $this->get($url)->assertForbidden();
        $this->document->forceFill(['effective_at' => now()->subHour()])->save();
        $this->get($url)->assertOk();
        $this->relationship->forceFill(['ended_at' => now()])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_rejected_or_revoked_authority_never_disables_the_adults_ordinary_access(): void
    {
        $this->signIn($this->adult);
        foreach (['pending', 'approved', 'rejected', 'needs_information', 'revoked'] as $state) {
            $this->document->forceFill(['status' => $state])->save();
            $this->get(route('secure-access.dashboard'))->assertOk();
            $this->get(route('secure-access.camp.index', 'bookings'))->assertOk();
            $this->get(route('secure-access.guest.index'))->assertOk();
        }
    }

    public function test_unknown_or_staff_capabilities_fail_closed_and_unauthorized_people_cannot_use_workspace(): void
    {
        $this->document->forceFill(['approved_scopes' => ['users.manage_all', 'health_profiles.read', 'bookings.read']])->save();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'health_profiles']))->assertForbidden();
        $this->assertSame(['bookings.read'], app(RepresentativeAccess::class)->scopes($this->relationship));
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.test', 'registration_role' => 'professional']);
        $this->signIn($other);
        $this->get(route('secure-access.representative.module', [$this->relationship, 'bookings']))->assertNotFound();
        $this->signIn($this->adult);
        $this->get(route('secure-access.representative.index'))->assertForbidden();
    }

    public function test_a_read_grant_does_not_allow_writing_and_domain_grants_do_not_imply_each_other(): void
    {
        $record = $this->booking($this->adult);
        $this->document->forceFill(['approved_scopes' => ['bookings.read', 'health_disclosures.create']])->save();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'bookings']))
            ->assertOk()->assertHeader('cache-control', 'no-store, private');
        $this->put(route('secure-access.representative.update', [$this->relationship, 'bookings', $record]), [
            'title' => 'Forbidden change', 'arrival_date' => '2099-01-03', 'departure_date' => '2099-01-04',
        ])->assertForbidden();
        $this->post(route('secure-access.representative.store', [$this->relationship, 'health_disclosures']), [
            'title' => 'Health disclosure', 'medical_history' => 'Restricted healthcare content',
        ])->assertRedirect();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'booking_balances']))->assertForbidden();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'health_profiles']))->assertForbidden();
        $this->assertSame('Private reservation', $record->fresh()->data['title']);
    }

    public function test_revoking_one_authority_does_not_remove_other_independent_current_grants(): void
    {
        $other = new TeamAuthorityDocument;
        $other->forceFill([
            'team_member_id' => $this->relationship->id, 'type' => 'power_of_attorney',
            'path' => 'private/second.pdf', 'file_name' => 'authority-document.pdf',
            'submitted_by' => (string) $this->representative->id, 'status' => 'approved',
            'approved_scopes' => ['booking_balances.read'], 'effective_at' => now()->subDay(),
        ])->save();
        $this->document->forceFill(['status' => 'revoked'])->save();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'bookings']))->assertForbidden();
        $this->get(route('secure-access.representative.module', [$this->relationship, 'booking_balances']))->assertOk();
    }

    public function test_public_activity_grant_does_not_expose_drafts_and_commitments_belong_to_the_adult(): void
    {
        $this->document->forceFill(['approved_scopes' => ['activities.read', 'activities.commit', 'activity_commitments.read']])->save();
        $activity = $this->booking($this->adult, [
            'module' => 'activities', 'is_public' => true,
            'data' => ['title' => 'Public activity', 'scheduled_at' => '2099-01-01', 'location' => 'Camp', 'description' => 'Published'],
        ]);
        $draft = $this->booking($this->adult, ['module' => 'activities', 'data' => ['title' => 'Unpublished activity']]);
        $this->get(route('secure-access.representative.module', [$this->relationship, 'activities']))
            ->assertOk()->assertSee('Public activity')->assertDontSee('Unpublished activity');
        $this->post(route('secure-access.representative.action', [$this->relationship, 'activities', $draft, 'commit']))->assertNotFound();
        $this->post(route('secure-access.representative.action', [$this->relationship, 'activities', $activity, 'commit']))->assertRedirect();
        $this->post(route('secure-access.representative.action', [$this->relationship, 'activities', $activity, 'commit']))->assertRedirect();
        $this->assertSame(1, CampRecord::where('module', 'activity_commitments')->count());
        $this->assertSame((string) $this->adult->id, CampRecord::where('module', 'activity_commitments')->sole()->owner_id);
    }

    public function test_delegated_upload_routes_require_mfa_and_invalid_data_cannot_be_saved(): void
    {
        $this->post(route('secure-access.representative.store', [$this->relationship, 'bookings']), [
            'title' => 'Invalid dates', 'arrival_date' => '2099-02-02', 'departure_date' => '2099-01-01',
        ])->assertSessionHasErrors('departure_date');
        $this->assertSame(0, CampRecord::count());
        $this->flushSession();
        $this->get(route('secure-access.representative.index'))->assertRedirect();
    }
}
