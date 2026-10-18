<?php

namespace SecureAccess\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\FinancialApplicationDecision;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Notifications\FinancialApplicationDecisionNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityReviewService;
use SecureAccess\Services\FinancialApplicationPermissionService;

class DeliverFinancialApplicationDecision implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $decisionId) {}

    public function handle(FinancialApplicationPermissionService $permissions): void
    {
        DB::transaction(function () use ($permissions) {
            $decision = FinancialApplicationDecision::query()->find($this->decisionId);
            if (! $decision) {
                return;
            }
            SecureAccess::newUserQuery()->whereKey($decision->ward_id)->lockForUpdate()->first();
            DepartmentAssignment::query()->whereKey($decision->assignment_id)->lockForUpdate()->first();
            TeamAuthorityDocument::query()->whereKey($decision->document_id)->lockForUpdate()->first();
            AuthorityDepartmentShare::query()->whereKey($decision->share_id)->lockForUpdate()->first();
            $delivery = DB::table('secure_access_financial_decision_deliveries')
                ->where('decision_id', $this->decisionId)->lockForUpdate()->first();
            if (! $delivery || $delivery->notified_at !== null || ! $permissions->eligible($decision)) {
                return;
            }
            $current = collect($permissions->currentDecisions($decision->ward_id))->contains(fn ($item) => $item->id === $decision->id);
            $recipient = SecureAccess::newUserQuery()->whereKey($decision->reviewer_id)->first();
            $email = $recipient?->getAttribute('email');
            if (! $current || ! is_string($email) || $email === '') {
                return;
            }
            Notification::route('mail', $email)->notify(new FinancialApplicationDecisionNotification);
            DB::table('secure_access_financial_decision_deliveries')->where('id', $delivery->id)->update(['notified_at' => now()]);
            app(AuthorityReviewService::class)->audit($decision->document, null, 'financial_application_decision_notified', [
                'decision_id' => $decision->id, 'share_id' => $decision->share_id,
                'assignment_id' => $decision->assignment_id, 'assignment_version' => $decision->assignment_version,
            ]);
        }, 3);
    }
}
