<?php

namespace SecureAccess\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\DepartmentDisclosureGrant;
use SecureAccess\Notifications\AuthorityDepartmentShareNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Services\AuthorityDepartmentShareService;

class DeliverAuthorityDepartmentDisclosure implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $grantId) {}

    public function handle(AuthorityDepartmentShareService $shares): void
    {
        DB::transaction(function () use ($shares) {
            $grant = DepartmentDisclosureGrant::query()->whereKey($this->grantId)->first();
            if (! $grant || ! $grant->active || $grant->notified_at !== null) {
                return;
            }
            $assignment = DepartmentAssignment::query()->whereKey($grant->assignment_id)->lockForUpdate()->first();
            $share = AuthorityDepartmentShare::query()->whereKey($grant->share_id)->lockForUpdate()->first();
            $grant = DepartmentDisclosureGrant::query()->whereKey($this->grantId)->lockForUpdate()->firstOrFail();
            if (! $grant->active || $grant->notified_at !== null) {
                return;
            }
            $recipient = SecureAccess::newUserQuery()->whereKey($grant->recipient_id)->first();
            if (! $share || ! $assignment || ! $recipient || $assignment->version !== $grant->assignment_version
                || $assignment->assignee_id !== $grant->recipient_id || $share->revision !== $grant->share_revision
                || ! $shares->canRead($recipient, $share, false)) {
                $shares->revokeGrant($grant, $share);

                return;
            }
            $email = $recipient->getAttribute('email');
            if (! is_string($email) || $email === '') {
                return;
            }
            Notification::route('mail', $email)->notify(new AuthorityDepartmentShareNotification($share->department));
            $grant->forceFill(['notified_at' => now()])->save();
            $shares->auditGrant($share, $grant, 'department_disclosure_notified');
        });
    }
}
