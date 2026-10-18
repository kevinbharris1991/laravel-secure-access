<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SecureAccess\Jobs\DeliverFinancialApplicationDecision;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\FinancialApplicationDecision;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\Notifications\FinancialApplicationDecisionNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Services\FinancialApplicationPermissionService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class FinancialApplicationPermissionTest extends TestCase
{
    private User $ward;
    private User $finance;
    private User $other;
    private TeamAuthorityDocument $document;
    private AuthorityDepartmentShare $share;
    private DepartmentAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->ward = $this->createUser(['username' => 'ward', 'email' => 'ward@example.com', 'registration_role' => 'self_advocate']);
        $this->finance = $this->createUser(['username' => 'finance', 'email' => 'finance@example.com', 'registration_role' => 'professional']);
        $this->other = $this->createUser(['username' => 'otherfinance', 'email' => 'otherfinance@example.com', 'registration_role' => 'professional']);
        foreach ([$this->finance, $this->other] as $actor) {
            $actor->accessGroups()->attach(AccessGroup::where('key', 'finance-accounting')->firstOrFail());
        }
        $relationship = TeamMember::create([
            'self_advocate_id' => (string) $this->ward->id, 'team_member_id' => (string) $this->other->id,
            'team_member_role' => 'professional',
        ]);
        $this->document = TeamAuthorityDocument::create([
            'team_member_id' => $relationship->id, 'ward_id' => (string) $this->ward->id,
            'type' => 'power_of_attorney', 'path' => 'private-original.pdf', 'file_name' => 'original.pdf',
            'submitted_by' => (string) $this->other->id, 'status' => 'approved',
            'review_decision' => 'confirmed', 'lifecycle_status' => 'active', 'effective_at' => now()->subDay(),
            'reviewed_by' => (string) $this->other->id, 'reviewed_at' => now(),
            'approved_scopes' => ['financial_aid.revoke', 'financial_aid.reinstate', 'tuition.revoke', 'tuition.reinstate'],
        ]);
        $this->share = AuthorityDepartmentShare::create([
            'document_id' => $this->document->id, 'department' => 'finance-accounting',
            'created_by' => (string) $this->other->id, 'summary' => 'Sanitized financial authority',
            'limitations' => 'Only the confirmed financial capabilities.', 'path' => 'redacted.pdf',
            'file_name' => 'redacted.pdf', 'status' => 'published', 'published_at' => now(), 'redacted_verified_at' => now(),
        ]);
        $this->assignment = DepartmentAssignment::create([
            'ward_id' => (string) $this->ward->id, 'department' => 'finance-accounting',
            'assignee_id' => (string) $this->finance->id, 'version' => 1,
        ]);
    }

    private function service(): FinancialApplicationPermissionService
    {
        return app(FinancialApplicationPermissionService::class);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'target' => 'financial_aid', 'action' => 'revoke', 'basis' => 'Private legal basis',
            'reason' => 'Private reviewer reason', 'effective_at' => now(config('app.timezone', 'UTC'))->toDateString(),
            'share_revision' => $this->share->revision,
        ];
    }

    private function login(User $actor): void
    {
        $this->actingAs($actor, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $actor->id,
        ]);
    }

    public function test_only_current_assigned_finance_can_record_expressly_supported_decisions(): void
    {
        $this->login($this->other);
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload())->assertForbidden();
        $this->login($this->finance);
        $this->document->update(['approved_scopes' => ['tuition.revoke']]);
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload())->assertForbidden();
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload(['target' => 'tuition']))->assertRedirect();
        $this->assertSame(1, FinancialApplicationDecision::count());
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
    }

    public function test_immutable_encrypted_evidence_and_metadata_only_audit_are_recorded(): void
    {
        $decision = $this->service()->decide($this->finance, $this->share, $this->payload());
        $this->assertSame((string) $this->finance->id, $decision->reviewer_id);
        $this->assertNotNull($decision->reviewed_at);
        $this->assertSame('Private legal basis', $decision->fresh()->basis);
        $raw = DB::table($decision->getTable())->where('id', $decision->id)->first();
        $this->assertStringNotContainsString('Private legal basis', $raw->basis);
        $this->assertStringNotContainsString('Private reviewer reason', $raw->reason);
        $audit = AuthorityDocumentAudit::where('action', 'financial_application_decision_recorded')->firstOrFail();
        $this->assertStringNotContainsString('Private', $audit->toJson());
        $this->expectException(\LogicException::class);
        $decision->update(['reason' => 'Rewrite history']);
    }

    public function test_future_effective_date_is_inclusive_in_application_timezone_and_reinstatement_is_not_early(): void
    {
        config(['app.timezone' => 'Pacific/Honolulu']);
        $this->travelTo(\Carbon\Carbon::parse('2026-07-01 23:59:00', 'Pacific/Honolulu'));
        $this->document->update(['effective_at' => now()->subDay()]);
        $this->service()->decide($this->finance, $this->share, $this->payload(['effective_at' => '2026-07-02']));
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
        $this->travelTo(\Carbon\Carbon::parse('2026-07-02 00:00:00', 'Pacific/Honolulu'));
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->service()->decide($this->finance, $this->share, $this->payload(['action' => 'reinstate', 'effective_at' => '2026-07-03']));
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->travelTo(\Carbon\Carbon::parse('2026-07-03 00:00:00', 'Pacific/Honolulu'));
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
    }

    public function test_both_targets_gate_applications_and_reinstatement_does_not_change_any_acl(): void
    {
        $permissions = DB::table('secure_access_memberships')->count();
        $this->service()->decide($this->finance, $this->share, $this->payload());
        $this->service()->decide($this->finance, $this->share, $this->payload(['target' => 'tuition']));
        $this->service()->decide($this->finance, $this->share, $this->payload(['action' => 'reinstate']));
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->service()->decide($this->finance, $this->share, $this->payload(['target' => 'tuition', 'action' => 'reinstate']));
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
        $this->assertSame($permissions, DB::table('secure_access_memberships')->count());
    }

    public function test_source_assignment_share_and_document_changes_remove_only_source_bound_deny_without_scheduler(): void
    {
        $this->service()->decide($this->finance, $this->share, $this->payload());
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->assignment->update(['version' => 2, 'assignee_id' => (string) $this->other->id]);
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
        $this->service()->decide($this->other, $this->share, $this->payload());
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->share->update(['revision' => 2]);
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
        $this->service()->decide($this->other, $this->share, $this->payload());
        $this->document->update(['lifecycle_status' => 'suspended']);
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
    }

    public function test_missing_basis_and_stale_disclosure_revision_are_rejected(): void
    {
        $this->login($this->finance);
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload(['basis' => '']))->assertSessionHasErrors('basis');
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload(['share_revision' => 99]))->assertStatus(409);
        $this->assertSame(0, FinancialApplicationDecision::count());
    }

    public function test_reinstatement_cannot_clear_a_different_authority_sources_deny(): void
    {
        $this->service()->decide($this->finance, $this->share, $this->payload());
        $second = $this->share->replicate();
        $second->save();
        $this->service()->decide($this->finance, $second, $this->payload(['action' => 'reinstate']));
        $this->assertFalse($this->service()->allowsApplications((string) $this->ward->id));
        $this->share->update(['status' => 'removed']);
        $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
    }

    public function test_current_review_lifecycle_dates_and_finance_department_are_required(): void
    {
        $this->service()->decide($this->finance, $this->share, $this->payload());
        foreach ([
            ['review_decision' => 'denied'], ['review_decision' => 'inquired'],
            ['lifecycle_status' => 'inactive'], ['lifecycle_status' => 'revoked'],
            ['lifecycle_status' => 'superseded'], ['effective_at' => now()->addDay()],
            ['expires_at' => now()->subDay()],
        ] as $change) {
            $original = $this->document->only(array_keys($change));
            $this->document->update($change);
            $this->assertTrue($this->service()->allowsApplications((string) $this->ward->id));
            $this->login($this->finance);
            $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload())->assertForbidden();
            $this->document->update($original);
        }
        $this->share->update(['department' => 'medical-nursing']);
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload())->assertForbidden();
        $this->assertSame(1, FinancialApplicationDecision::count());
    }

    public function test_sanitized_action_page_does_not_grant_original_document_access(): void
    {
        $this->login($this->finance);
        $this->get(route('secure-access.finance-actions.show', $this->share))->assertOk()
            ->assertSee('Sanitized financial authority')->assertDontSee('private-original.pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $this->get(route('secure-access.authority.show', $this->document))->assertNotFound();
    }

    public function test_delivery_rechecks_current_assignment_and_deduplicates_retries(): void
    {
        $decision = $this->service()->decide($this->finance, $this->share, $this->payload());
        $job = new DeliverFinancialApplicationDecision($decision->id);
        $job->handle($this->service());
        $job->handle($this->service());
        Notification::assertCount(1);
        Notification::assertSentOnDemand(FinancialApplicationDecisionNotification::class, function ($notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable)->toArray();
            $this->assertStringNotContainsString('Private', json_encode($mail));
            return $notifiable->routes['mail'] === $this->finance->email;
        });
        $later = $this->service()->decide($this->finance, $this->share, $this->payload(['target' => 'tuition']));
        $this->assignment->update(['version' => 2, 'assignee_id' => (string) $this->other->id]);
        (new DeliverFinancialApplicationDecision($later->id))->handle($this->service());
        Notification::assertCount(1);
    }

    public function test_due_decisions_are_rechecked_at_delivery_and_invalid_sources_cannot_act(): void
    {
        $this->travelTo(now()->startOfDay());
        $tomorrow = now()->addDay()->toDateString();
        $decision = $this->service()->decide($this->finance, $this->share, $this->payload(['effective_at' => $tomorrow]));
        (new DeliverFinancialApplicationDecision($decision->id))->handle($this->service());
        Notification::assertNothingSent();
        $this->travel(1)->days();
        $this->service()->synchronizePending();
        Queue::assertPushed(DeliverFinancialApplicationDecision::class, fn ($job) => $job->decisionId === $decision->id);
        $this->share->update(['status' => 'removed']);
        (new DeliverFinancialApplicationDecision($decision->id))->handle($this->service());
        Notification::assertNothingSent();
        $this->login($this->finance);
        $this->post(route('secure-access.finance-actions.store', $this->share), $this->payload())->assertForbidden();
    }
}
