<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SecureAccess\Jobs\DeliverFinancialApplicationDecision;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\FinancialApplicationDecision;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\SecureAccess;

class FinancialApplicationPermissionService
{
    public function __construct(protected AuthorityDepartmentShareService $shares) {}

    public function capabilities(Model $actor, AuthorityDepartmentShare $share): array
    {
        $share = $this->shares->readableShare($actor, $share);
        if (! $share || $share->department !== 'finance-accounting') {
            return [];
        }

        return array_values(array_intersect(
            ['financial_aid.revoke', 'financial_aid.reinstate', 'tuition.revoke', 'tuition.reinstate'],
            $share->document->approved_scopes ?? []
        ));
    }

    public function decide(Model $actor, AuthorityDepartmentShare $share, array $data): FinancialApplicationDecision
    {
        abort_unless($share->department === 'finance-accounting' && $this->shares->canRead($actor, $share), 403);
        $data = Validator::make($data, [
            'target' => ['required', Rule::in(FinancialApplicationDecision::TARGETS)],
            'action' => ['required', Rule::in(FinancialApplicationDecision::ACTIONS)],
            'basis' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:5000'],
            'effective_at' => ['required', 'date_format:Y-m-d'],
            'share_revision' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $share, $data) {
            $wardId = $this->shares->wardId($share->document);
            SecureAccess::newUserQuery()->whereKey($wardId)->lockForUpdate()->firstOrFail();
            $assignment = DepartmentAssignment::query()->where('ward_id', $wardId)
                ->where('department', 'finance-accounting')->lockForUpdate()->first();
            $document = TeamAuthorityDocument::query()->whereKey($share->document_id)->lockForUpdate()->firstOrFail();
            $share = AuthorityDepartmentShare::query()->whereKey($share->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($assignment && $assignment->assignee_id === (string) $actor->getKey()
                && $this->shares->canRead($actor, $share, false)
                && in_array($data['target'].'.'.$data['action'], $document->approved_scopes ?? [], true), 403);
            abort_unless($share->revision === (int) $data['share_revision'], 409);

            $decision = FinancialApplicationDecision::query()->create([
                'ward_id' => $wardId, 'document_id' => $document->getKey(), 'share_id' => $share->getKey(),
                'assignment_id' => $assignment->getKey(), 'assignment_version' => $assignment->version,
                'share_revision' => $share->revision, 'document_signature' => $this->signature($document),
                'reviewer_id' => (string) $actor->getKey(), 'reviewed_at' => now(),
                'target' => $data['target'], 'action' => $data['action'],
                'basis' => $data['basis'], 'reason' => $data['reason'], 'effective_at' => $data['effective_at'],
            ]);
            DB::table('secure_access_financial_decision_deliveries')->insert(['decision_id' => $decision->getKey()]);
            app(AuthorityReviewService::class)->audit($document, $actor, 'financial_application_decision_recorded', [
                'decision_id' => $decision->getKey(), 'share_id' => $share->getKey(),
                'assignment_id' => $assignment->getKey(), 'assignment_version' => $assignment->version,
                'target' => $decision->target, 'action' => $decision->action,
            ]);
            if ($this->isDue($decision)) {
                $this->dispatch($decision->getKey());
            }

            return $decision;
        }, 3);
    }

    public function allowsApplications(string $wardId): bool
    {
        // A source-bound deny is not legal termination and never changes the baseline ACL.
        // Invalid sources stop imposing overrides immediately, even without the scheduler.
        foreach ($this->currentDecisions($wardId) as $decision) {
            if ($decision->action === 'revoke') {
                return false;
            }
        }

        return true;
    }

    public function currentDecisions(string $wardId): array
    {
        $current = [];
        $decisions = FinancialApplicationDecision::query()->where('ward_id', $wardId)
            ->whereDate('effective_at', '<=', now(config('app.timezone', 'UTC'))->toDateString())
            ->orderByDesc('effective_at')->orderByDesc('id')->get();
        foreach ($decisions as $decision) {
            $key = $decision->document_id.':'.$decision->share_id.':'.$decision->target;
            if (! isset($current[$key]) && $this->eligible($decision)) {
                $current[$key] = $decision;
            }
        }

        return array_values($current);
    }

    public function eligible(FinancialApplicationDecision $decision): bool
    {
        $share = AuthorityDepartmentShare::query()->with('document.relationship')->find($decision->share_id);
        $assignment = DepartmentAssignment::query()->find($decision->assignment_id);
        $actor = SecureAccess::newUserQuery()->whereKey($decision->reviewer_id)->first();

        return $this->isDue($decision) && $share && $share->department === 'finance-accounting'
            && $share->document_id === $decision->document_id && $share->revision === $decision->share_revision
            && $assignment && $assignment->department === 'finance-accounting'
            && $assignment->ward_id === $decision->ward_id && $assignment->version === $decision->assignment_version
            && $assignment->assignee_id === $decision->reviewer_id && $actor
            && $this->shares->canRead($actor, $share, false)
            && $this->shares->wardId($share->document) === $decision->ward_id
            && hash_equals($decision->document_signature, $this->signature($share->document))
            && in_array($decision->target.'.'.$decision->action, $share->document->approved_scopes ?? [], true);
    }

    public function synchronizePending(): void
    {
        FinancialApplicationDecision::query()->whereDate('effective_at', '<=', now(config('app.timezone', 'UTC'))->toDateString())
            ->whereIn('id', DB::table('secure_access_financial_decision_deliveries')->select('decision_id')->whereNull('notified_at'))
            ->orderBy('id')->each(function ($decision) {
                if ($this->eligible($decision)) {
                    $this->dispatch($decision->getKey());
                }
            });
    }

    private function isDue(FinancialApplicationDecision $decision): bool
    {
        return $decision->effective_at->format('Y-m-d') <= now(config('app.timezone', 'UTC'))->toDateString();
    }

    private function signature(TeamAuthorityDocument $document): string
    {
        return hash('sha256', json_encode($document->only([
            'id', 'revision', 'review_decision', 'lifecycle_status', 'status', 'reviewed_by',
            'reviewed_at', 'approved_scopes', 'effective_at', 'expires_at', 'updated_at',
        ]), JSON_THROW_ON_ERROR));
    }

    private function dispatch(int $decisionId): void
    {
        DB::afterCommit(function () use ($decisionId) {
            try {
                DeliverFinancialApplicationDecision::dispatch($decisionId);
            } catch (\Throwable) {
                // The durable delivery row is retried by synchronization; no private context is logged.
            }
        });
    }
}
