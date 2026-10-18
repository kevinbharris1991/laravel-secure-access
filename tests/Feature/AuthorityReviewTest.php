<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\AuthorityRevocationEvidence;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Services\AuthorityOriginalStorage;
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
        $originals = Storage::fake('authority-originals');
        $storage = \Mockery::mock(AuthorityOriginalStorage::class)->makePartial();
        $storage->shouldReceive('disk')->andReturn($originals);
        $storage->shouldReceive('store')->andReturnUsing(function (UploadedFile $file, string $path) use ($originals) {
            $originals->put($path, file_get_contents($file->getRealPath()));
        });
        $this->app->instance(AuthorityOriginalStorage::class, $storage);
        Notification::fake();
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
            ...(in_array($decision, ['approved', 'confirm'], true) ? [
                'approved_scopes' => [$this->scope()],
                'effective_at' => now()->subMinute()->toDateTimeString(),
                'expires_at' => now()->addYear()->toDateTimeString(),
            ] : []),
        ]);
    }

    protected function submitEvidence(User $actor, TeamAuthorityDocument $document, array $extra = []): AuthorityRevocationEvidence
    {
        $this->login($actor);
        $response = $this->post(route('secure-access.authority.evidence.store', $document), $extra + [
            'document' => UploadedFile::fake()->create('private-legal-revocation.pdf', 100, 'application/pdf'),
            'comments' => 'Private supporting explanation',
        ]);
        $evidence = AuthorityRevocationEvidence::latest('id')->firstOrFail();
        $response->assertRedirect(route('secure-access.authority.evidence.show', $evidence));

        return $evidence;
    }

    protected function reviewEvidence(AuthorityRevocationEvidence $evidence, string $decision)
    {
        return $this->post(route('secure-access.authority.evidence.update', $evidence), [
            'decision' => $decision, 'comments' => 'Private verification rationale',
        ]);
    }

    public function test_submissions_are_pending_with_safe_filename_submitter_and_content_free_audit(): void
    {
        $document = $this->submit();
        $this->assertSame('pending', $document->status);
        $this->assertSame('pending', $document->review_decision);
        $this->assertSame('active', $document->lifecycle_status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->assertSame((string) $this->representative->getKey(), $document->submitted_by);
        $this->assertNull($document->approved_scopes);
        $this->assertSame(now()->toDateString().' - Otwell & Otwell - Guardianship - Rev1.pdf', $document->file_name);
        $audit = $document->audits()->firstOrFail();
        $this->assertSame('submitted', $audit->action);
        $this->assertSame([], $audit->metadata);
        $this->assertStringNotContainsString('sensitive-adult-name', $audit->toJson());
    }

    public function test_review_and_lifecycle_are_independent_and_activation_never_confirms_authority(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $lifecycle = route('secure-access.authority.lifecycle', $document);
        $this->post($lifecycle, ['lifecycle_status' => 'active'])->assertSessionHasErrors('comments');
        $this->post($lifecycle, ['lifecycle_status' => 'inactive', 'reason' => 'Website access paused.'])->assertRedirect();
        $this->decision($document, 'confirm')->assertRedirect();
        $this->assertSame('confirmed', $document->fresh()->review_decision);
        $this->assertSame('inactive', $document->fresh()->lifecycle_status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->post($lifecycle, ['lifecycle_status' => 'active', 'reason' => 'Website access restored.'])->assertRedirect();
        $this->assertContains($this->scope(), app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->assertSame((string) $this->reviewer->id, $document->fresh()->reviewed_by);
        $this->assertSame([$this->scope()], $document->fresh()->review_history[1]['confirmed_capabilities']);

        $pending = $this->submit(['type' => 'conservatorship']);
        $pending->forceFill(['approved_scopes' => ['health_disclosures.read'], 'status' => 'approved'])->save();
        $this->assertNotContains('health_disclosures.read', app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.lifecycle', $pending), [
            'lifecycle_status' => 'active', 'comments' => 'Not a review decision.',
        ])->assertRedirect();
        $this->assertSame('pending', $pending->fresh()->review_decision);
        $this->assertNotContains('health_disclosures.read', app(RepresentativeAccess::class)->scopes($this->relationship));
    }

    public function test_inquiry_remains_unresolved_until_a_subsequent_review_and_removes_authority(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'confirm', ['reason' => 'Authority verified.'])->assertRedirect();
        $this->decision($document, 'inquire', ['reason' => 'Clarify the authorized scope.'])->assertRedirect();
        $this->assertSame('inquired', $document->fresh()->review_decision);
        $this->assertSame('active', $document->fresh()->lifecycle_status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->post(route('secure-access.authority.lifecycle', $document), [
            'lifecycle_status' => 'active', 'comments' => 'This does not resolve the inquiry.',
        ])->assertRedirect();
        $this->assertSame('inquired', $document->fresh()->review_decision);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->decision($document, 'confirm', ['reason' => 'Clarification received and verified.'])->assertRedirect();
        $this->assertContains($this->scope(), app(RepresentativeAccess::class)->scopes($this->relationship));
        $this->assertSame(['confirmed', 'inquired', 'active', 'confirmed'],
            array_column($document->fresh()->review_history, 'decision'));
    }

    public function test_deny_records_a_review_without_fabricating_legal_termination(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'deny', ['reason' => 'The document does not establish authority.'])->assertRedirect();
        $this->assertSame('denied', $document->fresh()->review_decision);
        $this->assertSame('active', $document->fresh()->lifecycle_status);
        $this->assertNull($document->fresh()->termination_basis);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
    }

    public function test_date_only_authority_is_inclusive_through_the_configured_timezone_end_date(): void
    {
        $previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        try {
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-01 12:00:00', 'America/New_York'));
            $document = $this->submit();
            $this->login($this->reviewer);
            $this->decision($document, 'confirm', [
                'effective_at' => '2026-11-01', 'expires_at' => '2026-11-01',
            ])->assertRedirect();
            $this->assertSame('2026-11-01 00:00:00', $document->fresh()->effective_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-02 00:00:00', $document->fresh()->expires_at->format('Y-m-d H:i:s'));
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-01 23:59:59', 'America/New_York'));
            $this->assertContains($this->scope(), app(RepresentativeAccess::class)->scopes($this->relationship));
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-02 00:00:00', 'America/New_York'));
            $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
            app(AuthorityReviewService::class)->expire($this->relationship);
            $this->assertSame('confirmed', $document->fresh()->review_decision);
            $this->assertSame('expired', $document->fresh()->lifecycle_status);
            $this->post(route('secure-access.authority.lifecycle', $document), [
                'lifecycle_status' => 'active', 'reason' => 'Cannot bypass expiry.',
            ])->assertSessionHasErrors('lifecycle_status');
        } finally {
            $this->travelBack();
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_future_effective_authority_remains_ineligible_even_when_active(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'confirm', ['effective_at' => now()->addDay()->toDateString()])->assertRedirect();
        $this->post(route('secure-access.authority.lifecycle', $document), [
            'lifecycle_status' => 'active', 'reason' => 'Already active.',
        ])->assertRedirect();
        $this->assertSame('confirmed', $document->fresh()->review_decision);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
    }

    public function test_review_acl_upgrade_removes_only_non_ad_review_grants(): void
    {
        $group = AccessGroup::create(['key' => 'custom-authority-reviewer', 'name' => 'Custom reviewer']);
        $reviewPermission = DB::table('secure_access_permissions')->where('key', 'authority_documents.review_all')->value('id');
        $otherPermission = DB::table('secure_access_permissions')->where('key', 'system.read_all')->value('id');
        $group->permissions()->attach([$reviewPermission, $otherPermission]);
        $migration = require __DIR__.'/../../database/acl-migrations/2024_01_01_000012_restrict_authority_review_to_administrative_director.php';
        $migration->up();
        $this->assertFalse($group->permissions()->where('key', 'authority_documents.review_all')->exists());
        $this->assertTrue($group->permissions()->where('key', 'system.read_all')->exists());
        $this->assertTrue(AccessGroup::where('key', 'administrative-director')->firstOrFail()
            ->permissions()->where('key', 'authority_documents.review_all')->exists());
    }

    public function test_document_upgrade_backfills_decisions_without_inventing_review_for_disabled_legacy_records(): void
    {
        $approved = $this->submit();
        $approved->forceFill(['status' => 'approved'])->save();
        $inactive = $this->submit(['type' => 'conservatorship']);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $migration = require __DIR__.'/../../database/migrations/2024_01_01_000019_separate_authority_review_and_lifecycle.php';
        $migration->down();
        $migration->up();
        $this->assertSame('confirmed', $approved->fresh()->review_decision);
        $this->assertSame('active', $approved->fresh()->lifecycle_status);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('pending', $inactive->fresh()->review_decision);
        $this->assertSame('inactive', $inactive->fresh()->lifecycle_status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship));
    }

    public function test_only_administrative_directors_with_permission_can_review(): void
    {
        $document = $this->submit();
        $executive = $this->account('executive', 'professional');
        $executive->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());
        $this->login($executive);
        $this->get(route('secure-access.authority.index'))->assertForbidden();
        $this->get(route('secure-access.authority.show', $document))->assertNotFound();
        $this->decision($document, 'approved')->assertForbidden();
        $this->assertSame('pending', $document->fresh()->status);
        $permissionId = DB::table('secure_access_permissions')->where('key', 'authority_documents.review_all')->value('id');
        DB::table('secure_access_group_grants')->insertOrIgnore([
            'group_id' => AccessGroup::where('key', 'executive-director')->value('id'), 'permission_id' => $permissionId,
        ]);
        $this->decision($document, 'approved')->assertForbidden();

        $pending = $this->submit();
        $imposter = $this->account('lookalike-director', 'professional');
        $imposter->accessGroups()->attach(AccessGroup::create(['key' => 'lookalike', 'name' => 'Administrative Director']));
        $this->login($imposter);
        $this->get(route('secure-access.authority.index'))->assertForbidden();
        $this->decision($pending, 'approved')->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);
        $this->login($this->reviewer);
        $this->get(route('secure-access.authority.index'))->assertOk()->assertSee('Document #'.$pending->id)
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        DB::table('secure_access_group_grants')
            ->where('group_id', AccessGroup::where('key', 'administrative-director')->value('id'))
            ->where('permission_id', $permissionId)->delete();
        $this->decision($document, 'approved')->assertForbidden();
    }

    public function test_reviewer_can_mark_documents_inactive_or_superseded_with_encrypted_reason_and_audit(): void
    {
        $inactive = $this->submit();
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.lifecycle', $inactive), [
            'lifecycle_status' => 'inactive', 'comments' => 'Private review rationale',
        ])->assertRedirect();
        $this->assertSame('inactive', $inactive->fresh()->status);
        $this->assertSame('pending', $inactive->fresh()->review_decision);
        $this->assertSame('Private review rationale', $inactive->fresh()->review_history[0]['comments']);
        $this->assertNull($inactive->fresh()->approved_scopes);
        $inactiveAudit = $inactive->audits()->where('action', 'lifecycle_changed')->firstOrFail();
        $this->assertSame((string) $this->reviewer->id, $inactiveAudit->actor_id);
        $this->assertSame(['from_status' => 'pending', 'to_status' => 'inactive'], $inactiveAudit->metadata);
        $this->assertStringNotContainsString('Private review rationale', $inactiveAudit->toJson());
        Storage::disk('authority-originals')->assertExists($inactive->path);

        $superseded = $this->submit(['type' => 'conservatorship']);
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.lifecycle', $superseded), [
            'lifecycle_status' => 'superseded', 'comments' => 'Private review rationale',
        ])->assertRedirect();
        $this->assertSame('superseded', $superseded->fresh()->status);
        $this->assertSame('superseded', $superseded->fresh()->review_history[0]['decision']);
        Storage::disk('authority-originals')->assertExists($superseded->path);
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

    public function test_rejected_and_information_requested_documents_support_immutable_new_versions(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'needs_information')->assertRedirect();
        $replacement = $this->submit(['previous_document_id' => $document->id]);
        $this->assertSame($document->id, (int) $replacement->previous_document_id);
        $this->assertSame('pending', $replacement->status);
        $this->assertSame('needs_information', $document->fresh()->status);
        $this->assertNotSame($document->path, $replacement->path);
        Storage::disk('authority-originals')->assertExists($document->path);
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

    public function test_only_successful_approval_supersedes_previous_approval_and_direct_revocation_is_rejected(): void
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
        $this->decision($next, 'revoked')->assertSessionHasErrors('decision');
        $this->assertSame('approved', $next->fresh()->status);
        $this->decision($next, 'approved')->assertSessionHasErrors('decision');
        $this->assertCount(1, $next->fresh()->review_history);
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
        $file = UploadedFile::fake()->create('authority.pdf', 100, 'application/pdf');
        $storage = \Mockery::mock(AuthorityOriginalStorage::class)->makePartial();
        $storage->shouldReceive('store')->once()->andThrow(new \RuntimeException('Authority evidence could not be stored.'));
        $this->app->instance(AuthorityOriginalStorage::class, $storage);
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
            $this->get($url)->assertOk()->assertDownload($document->file_name)
                ->assertHeader('cache-control', 'no-store, private')
                ->assertHeader('x-content-type-options', 'nosniff');
        }
        $this->assertSame(3, $document->audits()->where('action', 'downloaded')->count());
        $this->login($this->account('outsider', 'professional'));
        $this->get($url)->assertNotFound();
        $this->assertSame(3, $document->audits()->where('action', 'downloaded')->count());
    }

    public function test_ending_relationship_suspends_access_preserves_files_and_blocks_upload_and_review(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        $pending = $this->submit();
        $this->delete(route('secure-access.team.relationships.destroy', $this->relationship))->assertRedirect();
        $this->assertNotNull($this->relationship->fresh()->ended_at);
        $this->assertSame('suspended', $document->fresh()->status);
        $this->assertSame('suspended', $pending->fresh()->status);
        Storage::disk('authority-originals')->assertExists($document->path);
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
        $this->assertSame('suspended', $document->fresh()->status);
        $this->assertSame([], app(RepresentativeAccess::class)->scopes($this->relationship->fresh()));
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertSessionHasErrors('decision');
        $newDocument = $this->submit();
        $this->assertSame('pending', $newDocument->status);
        $this->assertSame('suspended', $document->fresh()->status);
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
        Storage::disk('authority-originals')->assertExists($document->path);
        $migration->up();
        $this->assertDatabaseHas($document->getTable(), ['id' => $document->id, 'status' => 'pending']);
        $this->assertTrue(Schema::hasColumn('secure_access_team_members', 'ended_at'));
    }

    public function test_adult_representative_and_director_can_submit_evidence_without_changing_access(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        foreach ([$this->adult, $this->representative, $this->reviewer] as $actor) {
            $evidence = $this->submitEvidence($actor, $document);
            $this->assertSame('pending', $evidence->status);
            $this->assertSame((string) $actor->id, $evidence->submitted_by);
            $this->assertSame('approved', $document->fresh()->status);
            $this->assertSame('revocation-evidence.pdf', $evidence->file_name);
            $this->assertSame('Private supporting explanation', $evidence->comments);
            $raw = DB::table($evidence->getTable())->where('id', $evidence->id)->value('comments');
            $this->assertStringNotContainsString('Private supporting explanation', $raw);
            $this->assertDatabaseHas('secure_access_authority_document_audits', [
                'document_id' => $document->id, 'action' => 'revocation_evidence_submitted',
            ]);
            Storage::disk('local')->assertExists($evidence->path);
        }
        $this->assertSame(3, AuthorityRevocationEvidence::count());
        Storage::disk('authority-originals')->assertExists($document->path);
        $this->assertStringNotContainsString('Private supporting explanation', AuthorityDocumentAudit::all()->toJson());
        $this->assertStringNotContainsString('private-legal-revocation', AuthorityDocumentAudit::all()->toJson());
    }

    public function test_evidence_acceptance_records_verified_termination_only_for_target_document(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        $other = $this->submit(['type' => 'conservatorship']);
        $this->login($this->reviewer);
        $this->decision($other, 'approved')->assertRedirect();
        $adultBefore = $this->adult->fresh()->getAttributes();
        $evidence = $this->submitEvidence($this->adult, $document);
        $this->login($this->reviewer);
        $this->get(route('secure-access.authority.index'))->assertOk()->assertSee('Evidence #'.$evidence->id);
        $this->reviewEvidence($evidence, 'accepted')->assertRedirect();
        $this->assertSame('accepted', $evidence->fresh()->status);
        $this->assertSame('revoked', $document->fresh()->status);
        $this->assertSame('confirmed', $document->fresh()->review_decision);
        $this->assertSame('revoked', $document->fresh()->lifecycle_status);
        $this->assertSame('verified_evidence', $document->fresh()->termination_basis);
        $this->post(route('secure-access.authority.lifecycle', $document), [
            'lifecycle_status' => 'active', 'reason' => 'This must not undo accepted termination evidence.',
        ])->assertSessionHasErrors('lifecycle_status');
        $this->assertSame('approved', $other->fresh()->status);
        $this->assertSame($adultBefore, $this->adult->fresh()->getAttributes());
        $this->assertFalse(app(RepresentativeAccess::class)->grants($this->relationship)->whereKey($document->id)->exists());
        $this->assertTrue(app(RepresentativeAccess::class)->grants($this->relationship)->whereKey($other->id)->exists());
        Storage::disk('authority-originals')->assertExists($document->path);
        Storage::disk('local')->assertExists($evidence->path);
        $this->get(route('secure-access.authority.show', $document))->assertOk()->assertSee('Verified termination recorded');
        $audit = $document->audits()->where('action', 'verified_termination_recorded')->firstOrFail();
        $this->assertSame($evidence->id, $audit->metadata['evidence_id']);
        $this->assertStringNotContainsString('Private verification rationale', $audit->toJson());
        $this->reviewEvidence($evidence, 'rejected')->assertSessionHasErrors('decision');
    }

    public function test_rejected_or_information_requested_evidence_does_not_change_original_or_delete_files(): void
    {
        $document = $this->submit();
        $this->login($this->reviewer);
        $this->decision($document, 'approved')->assertRedirect();
        foreach (['rejected', 'needs_information'] as $decision) {
            $evidence = $this->submitEvidence($this->representative, $document);
            $this->login($this->reviewer);
            $this->reviewEvidence($evidence, $decision)->assertRedirect();
            $this->assertSame('approved', $document->fresh()->status);
            $this->assertSame($decision, $evidence->fresh()->status);
            $this->assertSame('Private verification rationale', $evidence->fresh()->review_history[0]['comments']);
            $raw = DB::table($evidence->getTable())->where('id', $evidence->id)->value('review_history');
            $this->assertStringNotContainsString('Private verification rationale', $raw);
            $this->reviewEvidence($evidence, 'accepted')->assertSessionHasErrors('decision');
            Storage::disk('local')->assertExists($evidence->path);
        }
        Storage::disk('authority-originals')->assertExists($document->path);
        $this->assertSame(2, AuthorityRevocationEvidence::count());
        $this->assertStringNotContainsString('Private verification rationale', AuthorityDocumentAudit::all()->toJson());
    }

    public function test_unrelated_users_cannot_submit_view_download_or_review_revocation_evidence(): void
    {
        $document = $this->submit();
        $evidence = $this->submitEvidence($this->adult, $document);
        $unrelated = $this->account('unrelated-evidence-user', 'professional');
        $unrelated->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());
        $this->login($unrelated);
        $this->post(route('secure-access.authority.evidence.store', $document), [
            'document' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        ])->assertNotFound();
        $this->get(route('secure-access.authority.evidence.show', $evidence))->assertNotFound();
        $this->get(route('secure-access.authority.evidence.download', $evidence))->assertNotFound();
        $this->reviewEvidence($evidence, 'accepted')->assertForbidden();
        $this->assertSame(1, AuthorityRevocationEvidence::count());
        $this->assertSame('pending', $document->fresh()->status);
        $this->assertSame(0, $document->audits()->where('action', 'revocation_evidence_downloaded')->count());
    }

    public function test_evidence_submitter_and_document_parties_cannot_review_even_as_directors(): void
    {
        $document = $this->submit();
        $evidence = $this->submitEvidence($this->reviewer, $document);
        foreach ([$this->adult, $this->representative] as $party) {
            $party->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
        }
        foreach ([$this->reviewer, $this->adult, $this->representative] as $actor) {
            $this->login($actor);
            $this->reviewEvidence($evidence, 'accepted')->assertForbidden();
        }
        $independent = $this->account('independent-director', 'professional');
        $independent->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
        $this->login($independent);
        $this->reviewEvidence($evidence, 'accepted')->assertRedirect();
        $this->assertSame('accepted', $evidence->fresh()->status);
    }

    public function test_evidence_access_is_private_audited_and_remains_available_after_website_relationship_ends(): void
    {
        $document = $this->submit();
        $this->delete(route('secure-access.team.relationships.destroy', $this->relationship))->assertRedirect();
        $this->assertNull($document->fresh()->termination_basis);
        $this->assertSame('suspended', $document->fresh()->status);
        $this->get(route('secure-access.team.index'))->assertOk()
            ->assertSee('Authority history for ended website relationships')
            ->assertSee(route('secure-access.authority.show', $document));
        $this->get(route('secure-access.authority.show', $document))->assertOk()
            ->assertSee('Website representative access suspended')->assertSee('not a determination');
        $evidence = $this->submitEvidence($this->representative, $document);
        foreach ([$this->adult, $this->representative, $this->reviewer] as $actor) {
            $this->login($actor);
            $this->get(route('secure-access.authority.evidence.show', $evidence))->assertOk()
                ->assertHeader('cache-control', 'no-store, private')
                ->assertHeader('x-content-type-options', 'nosniff');
            $this->get(route('secure-access.authority.evidence.download', $evidence))->assertOk()
                ->assertDownload('revocation-evidence.pdf')
                ->assertHeader('cache-control', 'no-store, private')
                ->assertHeader('x-content-type-options', 'nosniff');
        }
        $this->assertSame(3, $document->audits()->where('action', 'revocation_evidence_downloaded')->count());
        $this->assertSame(3, $document->audits()->where('action', 'revocation_evidence_viewed')->count());
        foreach ($document->audits()->where('action', 'like', 'revocation_evidence_%')->get() as $audit) {
            $this->assertSame(['evidence_id' => $evidence->id], $audit->metadata);
        }
        $this->reviewEvidence($evidence, 'accepted')->assertRedirect();
        $this->assertNotNull($this->relationship->fresh()->ended_at);
        $this->assertSame('revoked', $document->fresh()->status);
        $this->assertSame('verified_evidence', $document->fresh()->termination_basis);
        $audit = $document->audits()->where('action', 'verified_termination_recorded')->firstOrFail();
        $this->assertSame('suspended', $audit->metadata['from_status']);
        $this->assertSame('revoked', $audit->metadata['to_status']);
        Storage::disk('authority-originals')->assertExists($document->path);
    }

    public function test_evidence_files_and_review_comments_are_validated_and_routes_require_mfa(): void
    {
        $document = $this->submit();
        foreach ([
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ] as $file) {
            $this->post(route('secure-access.authority.evidence.store', $document), [
                'document' => $file,
            ])->assertSessionHasErrors('document');
        }
        $this->assertSame(0, AuthorityRevocationEvidence::count());
        $evidence = $this->submitEvidence($this->adult, $document);
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.evidence.update', $evidence), [
            'decision' => 'accepted', 'comments' => '   ',
        ])->assertSessionHasErrors('comments');
        $this->assertSame('pending', $evidence->fresh()->status);
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get(route('secure-access.authority.evidence.show', $evidence))->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.authority.evidence.download', $evidence))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.authority.evidence.update', $evidence))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.authority.evidence.store', $document))->assertRedirect(route('secure-access.login'));
    }

    public function test_evidence_submission_cannot_smuggle_accepted_status_or_reviewer_identity(): void
    {
        $document = $this->submit();
        $evidence = $this->submitEvidence($this->adult, $document, [
            'status' => 'accepted', 'submitted_by' => (string) $this->reviewer->id,
            'reviewed_by' => (string) $this->reviewer->id,
        ]);
        $this->assertSame('pending', $evidence->status);
        $this->assertSame((string) $this->adult->id, $evidence->submitted_by);
        $this->assertNull($evidence->reviewed_by);
        $this->assertSame('pending', $document->fresh()->status);
    }

    public function test_director_can_end_only_nurse_assigned_relationships_with_actual_actor_audit(): void
    {
        $document = $this->submit();
        $service = app(AuthorityReviewService::class);
        try {
            $service->endRelationship($this->reviewer, $this->relationship);
            $this->fail('A director must not end an unrelated voluntary relationship.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNull($this->relationship->fresh()->ended_at);
        DB::table('secure_access_team_members')->where('id', $this->relationship->id)->update([
            'nurse_assigned_by' => (string) $this->reviewer->id, 'nurse_assigned_at' => now(),
        ]);
        $otherDirector = $this->account('executive-only-director', 'professional');
        $otherDirector->accessGroups()->attach(AccessGroup::where('key', 'executive-director')->firstOrFail());
        try {
            $service->endRelationship($otherDirector, $this->relationship);
            $this->fail('Only exact Administrative Director membership may end a nurse assignment.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $service->endRelationship($this->reviewer, $this->relationship);
        $this->assertNotNull($this->relationship->fresh()->ended_at);
        $this->assertSame('suspended', $document->fresh()->status);
        $this->assertNull($document->fresh()->termination_basis);
        $audit = $document->audits()->where('action', 'relationship_ended')->firstOrFail();
        $this->assertSame((string) $this->reviewer->id, $audit->actor_id);
        Storage::disk('authority-originals')->assertExists($document->path);
    }
}
