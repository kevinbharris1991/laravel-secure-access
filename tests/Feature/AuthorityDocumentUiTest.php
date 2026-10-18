<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class AuthorityDocumentUiTest extends TestCase
{
    private function login(User $user): void
    {
        $this->actingAs($user, config('secure-access.guard'))->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);
    }

    private function document(): TeamAuthorityDocument
    {
        $adult = $this->createUser(['username' => 'adult-ui', 'email' => 'adult-ui@example.com', 'registration_role' => 'self_advocate']);
        $agent = $this->createUser(['username' => 'agent-ui', 'email' => 'agent-ui@example.com', 'registration_role' => 'professional']);
        $relationship = TeamMember::create([
            'self_advocate_id' => (string) $adult->getKey(),
            'team_member_id' => (string) $agent->getKey(),
            'team_member_role' => 'professional',
        ]);

        return TeamAuthorityDocument::create([
            'team_member_id' => $relationship->getKey(),
            'submitted_by' => (string) $agent->getKey(),
            'ward_id' => (string) $adult->getKey(),
            'revision' => 1,
            'type' => 'power_of_attorney',
            'path' => 'private-original.pdf',
            'file_name' => 'private-original.pdf',
            'review_history' => [[
                'decision' => 'confirmed',
                'comments' => 'Restricted reviewer-only rationale',
                'reviewed_by' => '12345',
                'confirmed_capabilities' => ['billing.read'],
                'reviewed_at' => now()->toIso8601String(),
            ]],
        ]);
    }

    public function test_independent_reviewer_sees_separate_review_lifecycle_and_evidence_forms(): void
    {
        $document = $this->document();
        $reviewer = $this->createUser(['username' => 'reviewer-ui', 'email' => 'reviewer-ui@example.com']);
        $reviewer->accessGroups()->attach(AccessGroup::where('key', 'administrative-director')->firstOrFail());
        $this->login($reviewer);

        $response = $this->get(route('secure-access.authority.show', $document))->assertOk()
            ->assertSee('Restricted reviewer-only rationale')
            ->assertSee('Review and lifecycle history')
            ->assertSee('Revision history')
            ->assertSee('Audit history')
            ->assertSee('Financial application permission decision history')
            ->assertSee('Rev1')
            ->assertSee('Reviewer account #12345')
            ->assertSee('Capabilities confirmed in this review')
            ->assertSee('billing.read')
            ->assertSee('name="decision"', false)
            ->assertSee('name="lifecycle_status"', false)
            ->assertSee(route('secure-access.authority.lifecycle', $document))
            ->assertSee('Confirmation does not establish legal authority')
            ->assertSee('Power of attorney — agent authority');

        preg_match_all('/<\/?form\b[^>]*>/i', $response->getContent(), $tags);
        $depth = 0;
        foreach ($tags[0] as $tag) {
            $depth += str_starts_with(strtolower($tag), '</form') ? -1 : 1;
            $this->assertContains($depth, [0, 1], 'Forms must not be nested.');
        }
        $this->assertSame(0, $depth);
    }

    public function test_document_party_does_not_see_review_revision_audit_or_management_controls(): void
    {
        $document = $this->document();
        $this->login(User::findOrFail($document->submitted_by));

        $this->get(route('secure-access.authority.show', $document))->assertOk()
            ->assertDontSee('Restricted reviewer-only rationale')
            ->assertDontSee('Review and lifecycle history')
            ->assertDontSee('Revision history')
            ->assertDontSee('Audit history')
            ->assertDontSee('Financial application permission decision history')
            ->assertDontSee('name="decision"', false)
            ->assertDontSee('name="lifecycle_status"', false)
            ->assertSee('Download original evidence')
            ->assertSee('Submit termination evidence for review');
    }

    public function test_financial_action_view_renders_only_sanitized_disclosure_and_capability_forms(): void
    {
        $html = view('secure-access::authority.finance-actions', [
            'share' => (object) [
                'id' => 123, 'department' => 'finance-accounting', 'revision' => 3,
                'summary' => '<script>private summary</script>', 'limitations' => 'Billing limitations only',
            ],
            'availableCapabilities' => ['financial_aid.revoke', 'tuition.reinstate'],
            'decisions' => [[
                'target' => 'financial_aid', 'action' => 'revoke', 'basis' => 'Financial decision basis',
                'reason' => 'Recorded financial reason', 'reviewer_name' => 'Assigned Officer',
                'reviewed_at' => '2026-10-18 12:00:00', 'effective_at' => '2026-10-19',
            ]],
            'errors' => new \Illuminate\Support\ViewErrorBag,
        ])->render();

        $this->assertStringContainsString('&lt;script&gt;private summary&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>private summary</script>', $html);
        $this->assertStringContainsString('name="share_revision" value="3"', $html);
        $this->assertStringContainsString('name="target" value="financial_aid"', $html);
        $this->assertStringContainsString('name="action" value="reinstate"', $html);
        $this->assertStringContainsString('Assigned Officer', $html);
        $this->assertStringContainsString('Financial decision basis', $html);
        $this->assertStringContainsString('Recorded financial reason', $html);
        $this->assertStringNotContainsString('Download original evidence', $html);
        $this->assertStringNotContainsString('dashboard/authority/', $html);
    }
}
