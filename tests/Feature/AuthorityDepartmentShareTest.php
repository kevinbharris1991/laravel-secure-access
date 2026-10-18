<?php

namespace SecureAccess\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Jobs\DeliverAuthorityDepartmentDisclosure;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\DepartmentDisclosureGrant;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\Notifications\AuthorityDepartmentShareNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityDepartmentShareService;
use SecureAccess\Services\DepartmentAssignmentService;
use SecureAccess\Services\NurseTeamService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AuthorityDepartmentShareTest extends TestCase
{
    protected User $representative;
    protected User $adult;
    protected User $reviewer;
    protected User $nurse;
    protected User $finance;
    protected TeamMember $relationship;
    protected TeamAuthorityDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        Queue::fake();
        $this->representative = $this->account('representative', 'professional');
        $this->adult = $this->account('adult', 'self_advocate');
        $this->reviewer = $this->account('director', 'professional', 'administrative-director');
        $this->nurse = $this->account('nurse', 'nurse', 'medical-nursing');
        $this->finance = $this->account('finance', 'professional', 'finance-accounting');
        $this->relationship = TeamMember::create([
            'self_advocate_id' => (string) $this->adult->id,
            'team_member_id' => (string) $this->representative->id,
            'team_member_role' => 'professional',
        ]);
        $this->document = TeamAuthorityDocument::create([
            'team_member_id' => $this->relationship->id, 'ward_id' => (string) $this->adult->id,
            'type' => 'power_of_attorney', 'path' => 'private-original.pdf', 'file_name' => 'original.pdf',
            'status' => 'approved', 'review_decision' => 'confirmed', 'lifecycle_status' => 'active',
            'submitted_by' => (string) $this->representative->id,
            'effective_at' => now()->subDay(), 'expires_at' => null,
        ]);
    }

    protected function account(string $username, string $role, ?string $group = null): User
    {
        $user = $this->createUser([
            'username' => $username, 'email' => $username.'@example.com', 'registration_role' => $role,
        ]);
        if ($group) {
            $user->accessGroups()->attach(AccessGroup::where('key', $group)->firstOrFail());
        }

        return $user;
    }

    protected function login(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id,
        ]);
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'department' => 'medical-nursing', 'summary' => 'Private healthcare summary',
            'limitations' => 'Only the explicitly listed authority applies.',
            'status' => 'published', 'redacted_confirmation' => '1',
            'document' => UploadedFile::fake()->create('redacted.pdf', 100, 'application/pdf'),
        ];
    }

    protected function createShare(array $overrides = []): AuthorityDepartmentShare
    {
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload($overrides))
            ->assertRedirect(route('secure-access.authority.show', $this->document));

        return AuthorityDepartmentShare::latest('id')->firstOrFail();
    }

    protected function assignNurse(?User $nurse = null): void
    {
        app(NurseTeamService::class)->assign($this->reviewer, (string) $this->adult->id, (string) ($nurse ?? $this->nurse)->id);
    }

    protected function deliver(): void
    {
        foreach (DepartmentDisclosureGrant::all() as $grant) {
            (new DeliverAuthorityDepartmentDisclosure($grant->id))->handle(app(AuthorityDepartmentShareService::class));
        }
    }

    public function test_only_current_assignees_can_read_their_department_and_access_is_audited(): void
    {
        $this->assignNurse();
        app(DepartmentAssignmentService::class)->assign($this->reviewer, (string) $this->adult->id, 'finance-accounting', (string) $this->finance->id);
        $medical = $this->createShare();
        $financial = $this->createShare(['department' => 'finance-accounting', 'summary' => 'Private financial summary']);
        $this->deliver();
        Notification::assertSentOnDemand(AuthorityDepartmentShareNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === $this->nurse->email;
        });
        $raw = DB::table($medical->getTable())->where('id', $medical->id)->value('summary');
        $this->assertStringNotContainsString('Private healthcare summary', $raw);
        Storage::disk('local')->assertExists($medical->path);
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $medical))->assertOk()
            ->assertSee('Private healthcare summary')->assertDontSee('private-original')
            ->assertHeader('cache-control', 'no-store, private');
        $this->get(route('secure-access.authority.shares.download', $medical))->assertOk()
            ->assertDownload('redacted-authority-document.pdf')->assertHeader('x-content-type-options', 'nosniff');
        $this->get(route('secure-access.authority.shares.show', $financial))->assertNotFound();
        $this->get(route('secure-access.authority.shares.department', 'finance-accounting'))->assertForbidden();
        $otherNurse = $this->account('othernurse', 'nurse', 'medical-nursing');
        $this->login($otherNurse);
        $this->get(route('secure-access.authority.shares.show', $medical))->assertNotFound();
        $this->get(route('secure-access.authority.shares.download', $medical))->assertNotFound();
        $this->get(route('secure-access.authority.shares.department', 'medical-nursing'))->assertOk()->assertDontSee('Shared authority item #'.$medical->id);
        $this->login($this->finance);
        $this->get(route('secure-access.authority.shares.show', $financial))->assertOk()->assertSee('Private financial summary');
        $this->assertSame(2, AuthorityDocumentAudit::where('action', 'department_share_created')->count());
        $this->assertSame(2, AuthorityDocumentAudit::where('action', 'department_share_viewed')->count());
        $this->assertSame(1, AuthorityDocumentAudit::where('action', 'department_share_downloaded')->count());
        $this->assertStringNotContainsString('Private healthcare summary', AuthorityDocumentAudit::all()->toJson());
    }

    public function test_drafts_are_private_and_publish_rechecks_and_notifies_existing_assignments_once(): void
    {
        $this->assignNurse();
        $share = $this->createShare(['status' => 'draft']);
        $this->assertSame(0, DepartmentDisclosureGrant::count());
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->login($this->reviewer);
        $this->put(route('secure-access.authority.shares.update', $share), [
            'summary' => 'Published summary', 'limitations' => 'Limited', 'status' => 'published',
        ])->assertRedirect();
        $service = app(AuthorityDepartmentShareService::class);
        $service->synchronizeForDocument($this->document);
        $service->synchronizeForWard((string) $this->adult->id);
        $this->assertSame(1, DepartmentDisclosureGrant::count());
        $this->deliver();
        $this->deliver();
        Notification::assertCount(1);
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $share))->assertOk()->assertSee('Published summary');
    }

    public function test_legacy_unscoped_copies_are_not_readable_or_publishable_without_reupload(): void
    {
        $this->assignNurse();
        $legacy = AuthorityDepartmentShare::create([
            'document_id' => $this->document->id, 'department' => 'medical-nursing', 'created_by' => (string) $this->reviewer->id,
            'summary' => 'Old unscoped copy', 'limitations' => 'Legacy', 'path' => 'legacy.pdf', 'file_name' => 'legacy.pdf',
        ]);
        app(AuthorityDepartmentShareService::class)->synchronizeForDocument($this->document);
        $this->assertSame('draft', $legacy->fresh()->status);
        $this->assertSame(0, DepartmentDisclosureGrant::count());
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $legacy))->assertNotFound();
        $this->login($this->reviewer);
        $this->put(route('secure-access.authority.shares.update', $legacy), [
            'summary' => 'Legacy', 'limitations' => 'Legacy', 'status' => 'published',
        ])->assertSessionHasErrors('document');
        $this->put(route('secure-access.authority.shares.update', $legacy), $this->payload())->assertRedirect();
        $this->assertSame('published', $legacy->fresh()->status);
    }

    public function test_document_decision_lifecycle_legacy_safety_and_relationship_are_rechecked_on_every_request(): void
    {
        $this->assignNurse();
        $share = $this->createShare();
        $this->login($this->nurse);
        foreach (['denied', 'inquired', 'pending'] as $decision) {
            $this->document->forceFill(['review_decision' => $decision])->save();
            $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        }
        $this->document->forceFill(['review_decision' => 'confirmed'])->save();
        foreach (['inactive', 'suspended', 'revoked', 'superseded', 'expired'] as $state) {
            $this->document->forceFill(['lifecycle_status' => $state])->save();
            $this->get(route('secure-access.authority.shares.download', $share))->assertNotFound();
        }
        $this->document->forceFill(['lifecycle_status' => 'active'])->save();
        foreach (['inactive', 'suspended', 'revoked', 'superseded', 'expired', 'rejected', 'needs_information'] as $state) {
            $this->document->forceFill(['status' => $state])->save();
            $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        }
        $this->document->forceFill(['status' => 'approved'])->save();
        $this->get(route('secure-access.authority.shares.show', $share))->assertOk();
        $this->relationship->forceFill(['ended_at' => now()])->save();
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->deliver();
        Notification::assertNothingSent();
    }

    public function test_inclusive_timezone_dates_future_activation_and_expiration_do_not_depend_on_scheduler_for_access(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);
        $this->travelTo(CarbonImmutable::parse('2026-05-02 00:30:00', 'UTC'));
        $this->document->forceFill(['effective_at' => '2026-05-01', 'expires_at' => '2026-05-03'])->save();
        $this->assignNurse();
        $share = $this->createShare(['effective_at' => '2026-05-02', 'expires_at' => '2026-05-02']);
        $this->assertSame(0, DepartmentDisclosureGrant::count());
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->travelTo(CarbonImmutable::parse('2026-05-02 07:00:00', 'UTC'));
        $this->get(route('secure-access.authority.shares.show', $share))->assertOk();
        $this->artisan('secure-access:sync-authority-disclosures')->assertSuccessful();
        $this->assertSame(1, DepartmentDisclosureGrant::count());
        $this->deliver();
        Notification::assertCount(1);
        $this->travelTo(CarbonImmutable::parse('2026-05-03 06:59:59', 'UTC'));
        $this->get(route('secure-access.authority.shares.show', $share))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-05-03 07:00:00', 'UTC'));
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->get(route('secure-access.authority.shares.download', $share))->assertNotFound();
        $this->artisan('secure-access:sync-authority-disclosures')->assertSuccessful();
        $this->assertFalse(DepartmentDisclosureGrant::sole()->active);
        $this->travelBack();
    }

    public function test_reassignment_revokes_old_access_and_stale_delivery_and_notifies_new_assignee(): void
    {
        $share = $this->createShare();
        $this->assertSame(0, DepartmentDisclosureGrant::count());
        $this->assignNurse();
        $oldGrant = DepartmentDisclosureGrant::sole();
        $replacement = $this->account('replacement', 'nurse', 'medical-nursing');
        $this->assignNurse($replacement);
        $this->assertSame(1, DepartmentAssignment::where('ward_id', (string) $this->adult->id)->where('department', 'medical-nursing')->count());
        $this->assertFalse($oldGrant->fresh()->active);
        (new DeliverAuthorityDepartmentDisclosure($oldGrant->id))->handle(app(AuthorityDepartmentShareService::class));
        Notification::assertNothingSent();
        $this->deliver();
        Notification::assertCount(1);
        Notification::assertSentOnDemand(AuthorityDepartmentShareNotification::class, fn ($notification, $channels, $notifiable) =>
            $notifiable->routes['mail'] === $replacement->email);
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->login($replacement);
        $this->get(route('secure-access.authority.shares.show', $share))->assertOk();
        $this->assertDatabaseHas('secure_access_department_assignment_audits', ['action' => 'reassigned']);
    }

    public function test_queued_delivery_rechecks_membership_acl_dates_and_content_revision(): void
    {
        $this->assignNurse();
        $share = $this->createShare();
        $grant = DepartmentDisclosureGrant::sole();
        $this->nurse->accessGroups()->detach();
        (new DeliverAuthorityDepartmentDisclosure($grant->id))->handle(app(AuthorityDepartmentShareService::class));
        Notification::assertNothingSent();
        $this->assertFalse($grant->fresh()->active);
        $this->nurse->accessGroups()->attach(AccessGroup::where('key', 'medical-nursing')->sole());
        $this->login($this->reviewer);
        $this->put(route('secure-access.authority.shares.update', $share), [
            'summary' => 'Revised private summary', 'limitations' => 'Limited', 'status' => 'published',
        ])->assertRedirect();
        (new DeliverAuthorityDepartmentDisclosure($grant->id))->handle(app(AuthorityDepartmentShareService::class));
        Notification::assertNothingSent();
        $this->document->forceFill(['expires_at' => now()->subDay()])->save();
        $this->deliver();
        Notification::assertNothingSent();
    }

    public function test_removal_is_audited_retains_redacted_file_and_cancels_pending_notification(): void
    {
        $this->assignNurse();
        $share = $this->createShare();
        $this->delete(route('secure-access.authority.shares.destroy', $share))->assertRedirect();
        $this->assertSame('removed', $share->fresh()->status);
        Storage::disk('local')->assertExists($share->path);
        $this->assertDatabaseHas('secure_access_authority_document_audits', ['action' => 'department_share_removed']);
        $this->login($this->nurse);
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->deliver();
        Notification::assertNothingSent();
    }

    public function test_management_requires_exact_director_group_and_review_acl(): void
    {
        $executive = $this->account('executive', 'professional', 'executive-director');
        $this->login($executive);
        $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload())->assertForbidden();
        $this->login($this->reviewer);
        $group = AccessGroup::where('key', 'administrative-director')->sole();
        $group->permissions()->detach();
        $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload())->assertForbidden();
        $this->assertSame(0, AuthorityDepartmentShare::count());
    }

    public function test_redacted_upload_requires_pdf_mime_extension_and_ten_mb_limit_and_confirmation(): void
    {
        $this->login($this->reviewer);
        foreach ([
            UploadedFile::fake()->image('redacted.png'),
            UploadedFile::fake()->create('redacted.pdf', 100, 'text/plain'),
            UploadedFile::fake()->create('redacted.jpg', 100, 'application/pdf'),
            UploadedFile::fake()->create('redacted.pdf', 10241, 'application/pdf'),
        ] as $file) {
            $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload(['document' => $file]))
                ->assertSessionHasErrors('document');
        }
        $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload(['redacted_confirmation' => '0']))
            ->assertSessionHasErrors('redacted_confirmation');
        $this->post(route('secure-access.authority.shares.store', $this->document), $this->payload([
            'effective_at' => '2026-05-03', 'expires_at' => '2026-05-02',
        ]))->assertSessionHasErrors('expires_at');
        $this->assertSame(0, AuthorityDepartmentShare::count());
    }

    public function test_notifications_contain_only_generic_authenticated_links_and_no_disclosure_data(): void
    {
        $mail = (new AuthorityDepartmentShareNotification('medical-nursing'))->toMail(new \stdClass);
        $rendered = json_encode($mail->toArray());
        $this->assertStringNotContainsString('Private healthcare summary', $rendered);
        $this->assertStringNotContainsString($this->adult->email, $rendered);
        $this->assertSame(route('secure-access.authority.shares.department', 'medical-nursing'), $mail->actionUrl);
        $this->get($mail->actionUrl)->assertRedirect(route('secure-access.login'));
    }
}
