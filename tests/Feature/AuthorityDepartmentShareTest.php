<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
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

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->representative = $this->account('share-representative', 'professional');
        $this->adult = $this->account('share-adult', 'self_advocate');
        $this->reviewer = $this->account('share-director', 'professional');
        $this->reviewer->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
        $this->nurse = $this->account('share-nurse', 'nurse');
        $this->nurse->accessGroups()->attach(AccessGroup::where('key', 'medical-nursing')->firstOrFail());
        $this->finance = $this->account('share-finance', 'professional');
        $this->finance->accessGroups()->attach(AccessGroup::where('key', 'finance-accounting')->firstOrFail());
        $this->relationship = TeamMember::create([
            'self_advocate_id' => (string) $this->adult->id,
            'team_member_id' => (string) $this->representative->id,
            'team_member_role' => 'professional',
        ]);
    }

    protected function account(string $username, string $role): User
    {
        return $this->createUser([
            'username' => $username, 'email' => $username.'@example.com', 'registration_role' => $role,
        ]);
    }

    protected function login(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id,
        ]);
    }

    protected function approvedDocument(): TeamAuthorityDocument
    {
        $this->login($this->representative);
        $this->post(route('secure-access.team.authority-documents.store', $this->relationship), [
            'type' => 'power_of_attorney',
            'document' => UploadedFile::fake()->create('original-sensitive-name.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $document = TeamAuthorityDocument::latest('id')->firstOrFail();

        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.update', $document), [
            'decision' => 'approved',
            'comments' => 'Verified for department sharing.',
            'approved_scopes' => ['health_disclosures.read'],
            'effective_at' => now()->subMinute()->toDateTimeString(),
            'expires_at' => now()->addYear()->toDateTimeString(),
        ])->assertRedirect();

        return $document->fresh();
    }

    protected function createShare(TeamAuthorityDocument $document, string $department, string $summary): AuthorityDepartmentShare
    {
        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.shares.store', $document), [
            'department' => $department,
            'summary' => $summary,
            'limitations' => 'Only the explicitly listed authority applies.',
            'redacted_confirmation' => '1',
            'document' => UploadedFile::fake()->create('redacted.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('secure-access.authority.show', $document));

        return AuthorityDepartmentShare::latest('id')->firstOrFail();
    }

    public function test_departments_only_access_their_own_shares_and_all_access_is_audited(): void
    {
        $document = $this->approvedDocument();
        $originalPath = $document->path;
        $medicalSummary = 'Healthcare authority summary';
        $healthcare = $this->createShare($document, 'medical-nursing', $medicalSummary);
        $financial = $this->createShare($document, 'finance-accounting', 'Financial authority summary');

        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($healthcare->path);
        Storage::disk('local')->assertExists($financial->path);
        $rawSummary = DB::table($healthcare->getTable())->where('id', $healthcare->id)->value('summary');
        $this->assertStringNotContainsString($medicalSummary, $rawSummary);
        Notification::assertSentOnDemand(\SecureAccess\Notifications\AuthorityDepartmentShareNotification::class);

        $this->login($this->nurse);
        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertSee(route('secure-access.authority.shares.department', 'medical-nursing'))
            ->assertDontSee(route('secure-access.authority.shares.department', 'finance-accounting'));
        $this->get(route('secure-access.authority.shares.department', 'medical-nursing'))->assertOk()
            ->assertSee('Shared authority item #'.$healthcare->id)
            ->assertDontSee('Shared authority item #'.$financial->id);
        $this->get(route('secure-access.authority.shares.show', $healthcare))->assertOk()
            ->assertSee($medicalSummary)->assertSee('Only the explicitly listed authority applies.')
            ->assertSee('Expires')->assertHeader('cache-control', 'no-store, private');
        $this->get(route('secure-access.authority.shares.download', $healthcare))->assertOk()
            ->assertDownload('redacted-authority-document.pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->get(route('secure-access.authority.shares.show', $financial))->assertNotFound();
        $this->get(route('secure-access.authority.shares.department', 'finance-accounting'))->assertForbidden();

        $this->login($this->finance);
        $this->get(route('secure-access.dashboard'))->assertOk()
            ->assertSee(route('secure-access.authority.shares.department', 'finance-accounting'))
            ->assertDontSee(route('secure-access.authority.shares.department', 'medical-nursing'));
        $this->get(route('secure-access.authority.shares.department', 'finance-accounting'))->assertOk()
            ->assertSee('Shared authority item #'.$financial->id)
            ->assertDontSee('Shared authority item #'.$healthcare->id);
        $this->get(route('secure-access.authority.shares.show', $healthcare))->assertNotFound();
        $this->get(route('secure-access.authority.shares.show', $financial))->assertOk()
            ->assertSee('Financial authority summary');

        $this->assertSame(2, AuthorityDocumentAudit::where('action', 'department_share_created')->count());
        $this->assertSame(4, AuthorityDocumentAudit::where('action', 'department_share_viewed')->count());
        $this->assertSame(1, AuthorityDocumentAudit::where('action', 'department_share_downloaded')->count());
        $this->assertSame((string) $this->reviewer->id, $document->audits()->where('action', 'department_share_created')
            ->firstOrFail()->actor_id);
        $this->assertStringNotContainsString($medicalSummary, AuthorityDocumentAudit::all()->toJson());
    }

    public function test_department_access_is_rechecked_for_expiry_inactivity_suspension_and_revocation(): void
    {
        $document = $this->approvedDocument();
        $share = $this->createShare($document, 'medical-nursing', 'Brief healthcare authority.');
        $this->login($this->nurse);

        foreach (['inactive', 'suspended', 'revoked', 'superseded', 'expired'] as $status) {
            $document->forceFill(['status' => $status])->save();
            $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        }

        $document->forceFill([
            'status' => 'approved', 'expires_at' => now()->subMinute(),
        ])->save();
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        $this->assertSame('expired', $document->fresh()->status);

        $document->forceFill(['status' => 'approved', 'expires_at' => null])->save();
        app(\SecureAccess\Services\AuthorityReviewService::class)->endRelationship($this->representative, $this->relationship);
        $this->get(route('secure-access.authority.shares.show', $share))->assertNotFound();
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('local')->assertExists($share->path);
    }

    public function test_only_directors_can_share_redacted_material_for_current_approved_authority(): void
    {
        $document = $this->approvedDocument();
        $outsider = $this->account('share-outsider', 'professional');
        $this->login($outsider);
        $this->post(route('secure-access.authority.shares.store', $document), [
            'department' => 'medical-nursing', 'summary' => 'Unauthorized', 'limitations' => 'None',
            'redacted_confirmation' => '1',
            'document' => UploadedFile::fake()->create('redacted.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
        $this->assertSame(0, AuthorityDepartmentShare::count());

        $this->login($this->reviewer);
        $this->post(route('secure-access.authority.update', $document), [
            'decision' => 'inactive', 'comments' => 'No longer active.',
        ])->assertRedirect();
        $this->post(route('secure-access.authority.shares.store', $document), [
            'department' => 'medical-nursing', 'summary' => 'Inactive', 'limitations' => 'None',
            'redacted_confirmation' => '1',
            'document' => UploadedFile::fake()->create('redacted.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
        $this->assertSame(0, AuthorityDepartmentShare::count());
    }
}
