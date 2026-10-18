<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AuthorityReviewTest extends TestCase
{
    protected User $representative;
    protected User $adult;
    protected User $reviewer;
    protected TeamMember $relationship;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->representative = $this->account('representative', 'professional');
        $this->adult = $this->account('adult', 'self_advocate');
        $this->reviewer = $this->account('reviewer', 'professional');
        $this->reviewer->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
        $this->relationship = TeamMember::create([
            'self_advocate_id' => (string) $this->adult->getKey(),
            'team_member_id' => (string) $this->representative->getKey(),
            'team_member_role' => 'professional',
        ]);
    }

    protected function account(string $username, string $role): User
    {
        return $this->createUser(['username' => $username, 'email' => $username.'@example.com', 'registration_role' => $role]);
    }

    protected function login(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);
    }

    protected function submit(array $extra = []): TeamAuthorityDocument
    {
        $this->login($this->representative);
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), $extra + [
            'type' => 'guardianship',
            'document' => UploadedFile::fake()->create('sensitive-adult-name.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('secure-access.team.index'));

        return TeamAuthorityDocument::latest('id')->firstOrFail();
    }

    protected function scope(): string
    {
        return array_merge([], ...array_values(app(RepresentativeAccess::class)->capabilities()))[0];
    }

    protected function decision(TeamAuthorityDocument $document, string $decision, array $extra = [])
    {
        return $this->post(route('secure-access.authority.update', $document), $extra + [
            'decision' => $decision, 'comments' => 'Private review rationale',
            ...($decision === 'approved' ? [
                'approved_scopes' => [$this->scope()],
                'effective_at' => now()->subMinute()->toDateTimeString(),
                'expires_at' => now()->addYear()->toDateTimeString(),
            ] : []),
        ]);
    }

    public function test_submissions_are_pending_with_safe_filename_submitter_and_content_free_audit(): void
    {
        $document = $this->submit();
        $this->assertSame('pending', $document->status);
        $this->assertSame((string) $this->representative->getKey(), $document->submitted_by);
        $this->assertNull($document->approved_scopes);
        $this->assertSame('authority-document.pdf', $document->file_name);
        $audit = $document->audits()->firstOrFail();
        $this->assertSame('submitted', $audit->action);
        $this->assertSame([], $audit->metadata);
        $this->assertStringNotContainsString('sensitive-adult-name', $audit->toJson());
    }

    public function test_only_exact_director_membership_can_review_not_other_manager_or_group_name(): void
    {
        $document = $this->submit();
        $manager = $this->account('manager', 'professional');
        $manager->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());
        $manager->accessGroups()->attach(AccessGroup::create(['key' => 'lookalike', 'name' => 'Administrative Director']));
        $this->login($manager);
        $this->get(route('secure-access.authority.index'))->assertForbidden();
        $this->decision($document, 'approved')->assertForbidden();
        $this->assertSame('pending', $document->fresh()->status);
        $this->login($this->reviewer);
        $this->get(route('secure-access.authority.index'))->assertOk()->assertSee('Document #'.$document->id)
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_participants_and_submitter_cannot_review_even_with_director_membership(): void
    {
        $document = $this->submit();
        foreach ([$this->representative, $this->adult] as $user) {
            $user->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
            $this->login($user);
            $this->decision($document, 'approved')->assertForbidden();
        }
        $document->forceFill(['submitted_by' => (string) $this->reviewer->getKey()])->save();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertForbidden();
    }

    public function test_comments_dates_and_exact_capabilities_are_required_for_approval(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved', ['comments' => '   '])->assertSessionHasErrors('comments');
        $this->decision($document, 'approved', ['approved_scopes' => ['*']])->assertSessionHasErrors('approved_scopes');
        $this->decision($document, 'approved', ['approved_scopes' => []])->assertSessionHasErrors('approved_scopes');
        $this->decision($document, 'approved', ['expires_at' => now()->subYear()->toDateTimeString()])->assertSessionHasErrors('expires_at');
        $this->decision($document, 'approved', ['effective_at' => null])->assertSessionHasErrors('effective_at');
        $this->assertSame('pending', $document->fresh()->status);
        $this->decision($document, 'approved')->assertRedirect();
        $this->assertSame('approved', $document->fresh()->status);
        $this->assertSame([$this->scope()], $document->fresh()->approved_scopes);
        $this->assertNotNull($document->fresh()->effective_at);
    }

    public function test_record_action_approval_requires_explicit_same_module_read_scope(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved', [
            'approved_scopes' => ['bookings.update'],
        ])->assertSessionHasErrors('approved_scopes');
        $this->assertSame('pending', $document->fresh()->status);
        $this->assertNull($document->fresh()->approved_scopes);
        $this->decision($document, 'approved', [
            'approved_scopes' => ['bookings.update', 'accommodations.read'],
        ])->assertSessionHasErrors('approved_scopes');
        $this->decision($document, 'approved', [
            'approved_scopes' => ['bookings.update', 'bookings.read'],
        ])->assertRedirect();
        $this->assertSame(['bookings.update', 'bookings.read'], $document->fresh()->approved_scopes);
    }

    public function test_nonexpiring_approvals_accept_missing_or_null_expiry_and_validate_provided_dates(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.update', $document), [
            'decision' => 'approved', 'comments' => 'Authority has no expiry.',
            'approved_scopes' => [$this->scope()], 'effective_at' => now()->toDateTimeString(),
        ])->assertRedirect();
        $this->assertSame('approved', $document->fresh()->status);
        $this->assertNull($document->fresh()->expires_at);
        $this->get(route('secure-access.authority.show', $document))->assertOk()->assertSee('No expiry');
        $second = $this->submit();
        $this->login($this->reviewer);
        $this->decision($second, 'approved', ['expires_at' => 'not-a-date'])->assertSessionHasErrors('expires_at');
        $this->decision($second, 'approved', ['expires_at' => null])->assertRedirect();
        $this->assertNull($second->fresh()->expires_at);
        $this->assertSame('approved', $second->fresh()->status);
    }

    public function test_queue_and_detail_views_are_audited_without_sensitive_metadata(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->get(route('secure-access.authority.index'))->assertOk();
        $this->get(route('secure-access.authority.show', $document))->assertOk();
        $this->login($this->adult);
        $this->get(route('secure-access.authority.show', $document))->assertOk();
        $views = $document->audits()->where('action', 'viewed')->get();
        $this->assertCount(3, $views);
        foreach ($views as $audit) {
            $this->assertSame([], $audit->metadata);
            $this->assertStringNotContainsString('sensitive-adult-name', $audit->toJson());
        }
        $this->login($this->account('unrelated-viewer', 'professional'));
        $this->get(route('secure-access.authority.show', $document))->assertNotFound();
        $this->assertSame(3, $document->audits()->where('action', 'viewed')->count());
    }

    public function test_review_history_is_encrypted_and_never_copied_to_audit_metadata(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'needs_information')->assertRedirect();
        $raw = DB::table($document->getTable())->where('id', $document->id)->value('review_history');
        $this->assertStringNotContainsString('Private review rationale', $raw);
        $this->assertSame('Private review rationale', $document->fresh()->review_history[0]['comments']);
        $this->assertStringNotContainsString('Private review rationale', AuthorityDocumentAudit::all()->toJson());
        $this->get(route('secure-access.authority.show', $document))->assertOk()->assertSee('Private review rationale')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->login($this->adult);
        $this->get(route('secure-access.authority.show', $document))->assertOk()->assertSee('Private review rationale');
        $this->login($this->account('outsider', 'professional'));
        $this->get(route('secure-access.authority.show', $document))->assertNotFound();
    }

    public function test_rejected_and_information_requested_documents_require_immutable_new_versions(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'needs_information')->assertRedirect();
        $this->decision($document, 'approved')->assertSessionHasErrors('decision');
        $replacement = $this->submit(['previous_document_id' => $document->id]);
        $this->assertSame($document->id, (int) $replacement->previous_document_id);
        $this->assertSame('pending', $replacement->status);
        $this->assertSame('needs_information', $document->fresh()->status);
        $this->assertNotSame($document->path, $replacement->path);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas('secure_access_authority_document_audits', ['document_id' => $replacement->id, 'action' => 'resubmitted']);
        $this->login($this->reviewer);
        $this->decision($replacement, 'rejected')->assertRedirect();
        $third = $this->submit(['previous_document_id' => $replacement->id]);
        $this->assertSame($replacement->id, (int) $third->previous_document_id);
    }

    public function test_resubmission_rejects_wrong_relationship_type_status_and_duplicate_version(): void
    {
        $document = $this->submit();
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'guardianship', 'previous_document_id' => $document->id,
            'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('previous_document_id');
        $document->forceFill(['status' => 'rejected'])->save();
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'conservatorship', 'previous_document_id' => $document->id,
            'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('previous_document_id');
        $other = TeamMember::create([
            'self_advocate_id' => (string) $this->account('other-adult', 'self_advocate')->getKey(),
            'team_member_id' => (string) $this->representative->id, 'team_member_role' => 'professional',
        ]);
        $this->post(route('secure-access.team.authority-documents.store', $other), [
            'type' => 'guardianship', 'previous_document_id' => $document->id,
            'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('previous_document_id');
        $this->submit(['previous_document_id' => $document->id]);
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'guardianship', 'previous_document_id' => $document->id,
            'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('previous_document_id');
        $this->assertSame(2, TeamAuthorityDocument::count());
    }

    public function test_only_successful_approval_supersedes_previous_approval_and_approved_can_be_revoked(): void
    {
        $approved = $this->submit();
        $this->login($this->reviewer);
        $this->decision($approved, 'approved')->assertRedirect();
        $replacement = $this->submit();
        $this->login($this->reviewer);
        $this->decision($replacement, 'rejected')->assertRedirect();
        $this->assertSame('approved', $approved->fresh()->status);
        $next = $this->submit(['previous_document_id' => $replacement->id]);
        $this->login($this->reviewer);
        $this->decision($next, 'approved')->assertRedirect();
        $this->assertSame('superseded', $approved->fresh()->status);
        $this->decision($next, 'revoked')->assertRedirect();
        $this->assertSame('revoked', $next->fresh()->status);
        $this->decision($next, 'approved')->assertSessionHasErrors('decision');
        $this->assertCount(2, $next->fresh()->review_history);
    }

    public function test_future_effective_replacement_cannot_interrupt_an_existing_active_grant(): void
    {
        $approved = $this->submit();
        $this->login($this->reviewer);
        $this->decision($approved, 'approved')->assertRedirect();
        $replacement = $this->submit();
        $this->login($this->reviewer);
        $this->decision($replacement, 'approved', [
            'effective_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('effective_at');
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('pending', $replacement->fresh()->status);
        $this->assertSame(0, $approved->audits()->where('action', 'superseded')->count());
        $this->decision($replacement, 'approved')->assertRedirect();
        $this->assertSame('superseded', $approved->fresh()->status);
    }

    public function test_storage_failure_does_not_create_document_or_submission_audit(): void
    {
        $file = \Mockery::mock(UploadedFile::class);
        $file->shouldReceive('store')->once()->with('secure-access/authority-documents', 'local')->andReturn(false);
        try {
            app(AuthorityReviewService::class)->submit(
                $this->representative, $this->relationship, ['type' => 'guardianship'], $file
            );
            $this->fail('Storage failure must prevent submission.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Authority evidence could not be stored.', $exception->getMessage());
        }
        $this->assertSame(0, TeamAuthorityDocument::count());
        $this->assertSame(0, AuthorityDocumentAudit::count());
    }

    public function test_resubmissions_cannot_omit_lineage_after_rejection_or_information_request(): void
    {
        foreach (['rejected', 'needs_information'] as $index => $decision) {
            $type = TeamAuthorityDocument::TYPES[$index];
            $document = $this->submit(['type' => $type]);
            $this->login($this->reviewer);
            $this->decision($document, $decision)->assertRedirect();
            $this->login($this->representative);
            $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
                'type' => $type, 'document' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf'),
            ])->assertSessionHasErrors('previous_document_id');
            $replacement = $this->submit(['type' => $type, 'previous_document_id' => $document->id]);
            $this->assertSame($document->id, (int) $replacement->previous_document_id);
        }
        $this->assertSame(4, TeamAuthorityDocument::count());
    }

    public function test_downloads_are_private_audited_and_available_to_reviewer_and_participants_only(): void
    {
        $document = $this->submit();
        $url = route('secure-access.team.authority-documents.download', [$this->relationship, $document]);
        $this->assertStringNotContainsString('sensitive-adult-name', $url);
        foreach ([$this->reviewer, $this->adult, $this->representative] as $user) {
            $this->login($user);
            $this->get($url)->assertOk()->assertDownload('authority-document.pdf')
                ->assertHeader('cache-control', 'no-store, private')
                ->assertHeader('x-content-type-options', 'nosniff');
        }
        $this->assertSame(3, $document->audits()->where('action', 'downloaded')->count());
        $this->login($this->account('outsider', 'professional'));
        $this->get($url)->assertNotFound();
        $this->assertSame(3, $document->audits()->where('action', 'downloaded')->count());
    }

    public function test_ending_relationship_revokes_access_preserves_files_and_blocks_upload_and_review(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        $pending = $this->submit();
        $this->delete(route('secure-access.team.relationships.destroy', $this->relationship))->assertRedirect();
        $this->assertNotNull($this->relationship->fresh()->ended_at);
        $this->assertSame('revoked', $document->fresh()->status);
        $this->assertSame('revoked', $pending->fresh()->status);
        Storage::disk('local')->assertExists($document->path);
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'guardianship', 'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertNotFound();
        $this->get(route('secure-access.team.index'))->assertOk()->assertViewHas('team', fn ($team) => $team->isEmpty());
        $this->login($this->reviewer);
        $this->decision($pending, 'approved')->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('secure_access_authority_document_audits', [
            'document_id' => $document->id, 'action' => 'relationship_ended',
        ]);
    }

    public function test_expiry_is_recorded_once_on_document_access_and_cannot_be_reapproved(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved', ['expires_at' => now()->addMinute()->toDateTimeString()])->assertRedirect();
        $this->travel(2)->minutes();
        $this->get(route('secure-access.authority.show', $document))->assertOk()->assertSee('Expired');
        app(AuthorityReviewService::class)->expire($this->relationship, $this->reviewer);
        $this->assertSame('expired', $document->fresh()->status);
        $this->assertSame(1, $document->audits()->where('action', 'expired')->count());
        $this->decision($document, 'approved')->assertSessionHasErrors('decision');
    }

    public function test_review_routes_require_authentication_and_verified_mfa(): void
    {
        $document = TeamAuthorityDocument::create([
            'team_member_id' => $this->relationship->id, 'type' => 'guardianship',
            'path' => 'private-file', 'file_name' => 'authority-document.pdf',
        ]);
        $this->get(route('secure-access.authority.index'))->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.authority.show', $document))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.authority.update', $document))->assertRedirect(route('secure-access.login'));
        $this->actingAs($this->reviewer, config('secure-access.guard'));
        $this->get(route('secure-access.authority.index'))->assertRedirect(route('secure-access.login'));
    }

    public function test_oversized_evidence_is_rejected_and_approval_fields_cannot_be_smuggled_on_upload(): void
    {
        $this->login($this->representative);
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'guardianship', 'document' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ])->assertSessionHasErrors('document');
        $this->assertSame(0, TeamAuthorityDocument::count());
        $document = $this->submit([
            'status' => 'approved', 'approved_scopes' => [$this->scope()],
            'submitted_by' => (string) $this->reviewer->id,
        ]);
        $this->assertSame('pending', $document->status);
        $this->assertNull($document->approved_scopes);
        $this->assertSame((string) $this->representative->id, $document->submitted_by);
    }

    public function test_reaccepting_an_invitation_reactivates_only_relationship_not_historical_authority(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        $this->login($this->representative);
        $this->delete(route('secure-access.team.relationships.destroy', $this->relationship))->assertRedirect();
        $invitation = \SecureAccess\Models\TeamInvitation::create([
            'inviter_id' => (string) $this->representative->id,
            'invitee_id' => (string) $this->adult->id,
            'invitee_email' => $this->adult->email,
            'inviter_role' => 'professional', 'invitee_role' => 'self_advocate', 'status' => 'pending',
        ]);
        $this->login($this->adult);
        $this->post(route('secure-access.team.invitations.accept', $invitation))->assertRedirect();
        $this->assertNull($this->relationship->fresh()->ended_at);
        $this->assertSame(1, TeamMember::count());
        $this->assertSame('revoked', $document->fresh()->status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship->fresh()));
    }

    public function test_review_migration_rollback_and_reapply_preserves_existing_evidence(): void
    {
        $document = $this->submit();
        $migration = require __DIR__.'/../../database/migrations/2024_01_01_000014_add_authority_document_review.php';
        $migration->down();
        $this->assertFalse(Schema::hasColumn('secure_access_team_members', 'ended_at'));
        $this->assertFalse(Schema::hasColumn($document->getTable(), 'status'));
        $this->assertFalse(Schema::hasTable('secure_access_authority_document_audits'));
        $this->assertDatabaseHas($document->getTable(), ['id' => $document->id, 'path' => $document->path]);
        Storage::disk('local')->assertExists($document->path);
        $migration->up();
        $this->assertDatabaseHas($document->getTable(), ['id' => $document->id, 'status' => 'pending']);
        $this->assertTrue(Schema::hasColumn('secure_access_team_members', 'ended_at'));
    }
}
