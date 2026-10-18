<?php

namespace SecureAccess\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Jobs\DeliverAuthorityDepartmentDisclosure;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\DepartmentDisclosureGrant;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;

class AuthorityDepartmentShareService
{
    public function __construct(protected AuthorityReviewService $reviews) {}

    public function canManage(Model $actor, TeamAuthorityDocument $document): bool
    {
        return app(AccessControl::class)->isMember($actor, 'administrative-director')
            && $this->reviews->canReview($actor, $document);
    }

    public function canShare(Model $actor, TeamAuthorityDocument $document): bool
    {
        return $this->canManage($actor, $document);
    }

    public function canRead(Model $actor, AuthorityDepartmentShare $share, bool $synchronize = true): bool
    {
        return $this->readableShare($actor, $share, $synchronize) !== null;
    }

    public function readableShare(Model $actor, AuthorityDepartmentShare $share, bool $synchronize = true): ?AuthorityDepartmentShare
    {
        // Never trust a cached relation or a previously issued delivery grant for authorization.
        if ($synchronize) {
            $this->synchronize($share);
        }
        $share = AuthorityDepartmentShare::query()->with('document.relationship')->find($share->getKey());
        if (! $share || ! $this->eligible($share) || ! $this->canAccessDepartment($actor, $share->department)) {
            return null;
        }

        $assigned = DepartmentAssignment::query()->where('ward_id', $this->wardId($share->document))
            ->where('department', $share->department)->where('assignee_id', (string) $actor->getKey())->exists()
            && $this->assignmentIsLive($share->department, $this->wardId($share->document), (string) $actor->getKey());

        return $assigned ? $share : null;
    }

    public function share(Model $actor, TeamAuthorityDocument $document, array $data, UploadedFile $file): AuthorityDepartmentShare
    {
        abort_unless($this->canManage($actor, $document), 403);
        $data = $this->validate($data, $file, true);
        $path = null;
        try {
            $path = $this->storeFile($file);
            return DB::transaction(function () use ($actor, $document, $data, $path) {
                $document = TeamAuthorityDocument::query()->with('relationship')->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($this->canManage($actor, $document), 403);
                abort_if(($data['status'] ?? 'draft') === 'published' && ! $this->sourceEligible($document, false), 403);
                $share = AuthorityDepartmentShare::query()->create([
                    'document_id' => $document->getKey(),
                    'department' => $data['department'],
                    'created_by' => (string) $actor->getKey(),
                    'summary' => $data['summary'],
                    'limitations' => $data['limitations'],
                    'status' => $data['status'] ?? 'draft',
                    'effective_at' => $data['effective_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'published_at' => ($data['status'] ?? 'draft') === 'published' ? now() : null,
                    'redacted_verified_at' => now(),
                    'path' => $path,
                    'file_name' => 'redacted-authority-document.pdf',
                ]);
                $this->reviews->audit($document, $actor, 'department_share_created', [
                    'share_id' => $share->getKey(), 'department' => $share->department, 'to_status' => $share->status,
                ]);
                if ($share->status === 'published') {
                    $this->reviews->audit($document, $actor, 'department_share_published', [
                        'share_id' => $share->getKey(), 'department' => $share->department, 'to_status' => 'published',
                    ]);
                }
                $this->synchronizeForDocument($document);

                return $share;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path && ! AuthorityDepartmentShare::query()->where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function update(Model $actor, AuthorityDepartmentShare $share, array $data, ?UploadedFile $file = null): AuthorityDepartmentShare
    {
        abort_unless($this->canManage($actor, $share->document), 403);
        $data += [
            'effective_at' => $share->effective_at?->toDateString(),
            'expires_at' => $share->expires_at?->toDateString(),
        ];
        $data = $this->validate($data, $file, false);
        $path = null;
        try {
            $path = $file ? $this->storeFile($file) : null;
            return DB::transaction(function () use ($actor, $share, $data, $file, $path) {
                $share = AuthorityDepartmentShare::query()->whereKey($share->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($this->canManage($actor, $share->document), 403);
                $previousStatus = $share->status;
                if ($file) {
                    $share->path = $path;
                    $share->file_name = 'redacted-authority-document.pdf';
                    $share->redacted_verified_at = now();
                }
                $share->fill(collect($data)->only(['summary', 'limitations', 'status', 'effective_at', 'expires_at'])->all());
                if ($share->status === 'published') {
                    abort_unless($this->sourceEligible($share->document, false), 403);
                    // An old image or an unverified legacy copy cannot be published by changing only its status.
                    if ($share->redacted_verified_at === null || pathinfo($share->file_name, PATHINFO_EXTENSION) !== 'pdf') {
                        throw ValidationException::withMessages(['document' => __('Upload a verified redacted PDF before publishing.')]);
                    }
                }
                if (! $share->isDirty()) {
                    $this->synchronizeForDocument($share->document);

                    return $share;
                }
                if ($share->status === 'published') {
                    $share->published_at = now();
                }
                $share->revision++;
                $share->save();
                $this->reviews->audit($share->document, $actor, 'department_share_updated', [
                    'share_id' => $share->getKey(), 'department' => $share->department,
                    'from_status' => $previousStatus, 'to_status' => $share->status,
                ]);
                if ($share->status === 'published') {
                    $this->reviews->audit($share->document, $actor, 'department_share_published', [
                        'share_id' => $share->getKey(), 'department' => $share->department,
                        'from_status' => $previousStatus, 'to_status' => 'published',
                    ]);
                }
                $this->synchronizeForDocument($share->document);

                return $share;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path && ! AuthorityDepartmentShare::query()->where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function remove(Model $actor, AuthorityDepartmentShare $share): void
    {
        abort_unless($this->canManage($actor, $share->document), 403);
        DB::transaction(function () use ($actor, $share) {
            $share = AuthorityDepartmentShare::query()->whereKey($share->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($this->canManage($actor, $share->document), 403);
            if ($share->status === 'removed') {
                return;
            }
            $previousStatus = $share->status;
            $share->forceFill(['status' => 'removed', 'revision' => $share->revision + 1])->save();
            $this->reviews->audit($share->document, $actor, 'department_share_removed', [
                'share_id' => $share->getKey(), 'department' => $share->department,
                'from_status' => $previousStatus, 'to_status' => 'removed',
            ]);
            $this->synchronizeForDocument($share->document);
        }, 3);
    }

    public function notifyDepartment(AuthorityDepartmentShare $share): void
    {
        $this->synchronizeForDocument($share->document);
    }

    public function synchronizeForDocument(TeamAuthorityDocument $document): void
    {
        AuthorityDepartmentShare::query()->where('document_id', $document->getKey())->orderBy('id')
            ->each(fn (AuthorityDepartmentShare $share) => $this->synchronize($share));
    }

    public function synchronizeForWard(string $wardId): void
    {
        TeamAuthorityDocument::query()->whereHas('relationship', fn ($query) => $query->where('self_advocate_id', $wardId))
            ->orderBy('id')->each(fn (TeamAuthorityDocument $document) => $this->synchronizeForDocument($document));
    }

    public function synchronize(AuthorityDepartmentShare $share): void
    {
        DB::transaction(function () use ($share) {
            $assignment = DepartmentAssignment::query()->where('ward_id', $this->wardId($share->document))
                ->where('department', $share->department)->lockForUpdate()->first();
            $share = AuthorityDepartmentShare::query()->with('document.relationship')->whereKey($share->getKey())->lockForUpdate()->firstOrFail();
            $recipient = $assignment?->assignee_id
                ? SecureAccess::newUserQuery()->whereKey($assignment->assignee_id)->first() : null;
            $eligible = $recipient && $this->eligible($share)
                && $this->canAccessDepartment($recipient, $share->department)
                && $this->assignmentIsLive($share->department, $assignment->ward_id, $assignment->assignee_id);
            $grants = DepartmentDisclosureGrant::query()->where('share_id', $share->getKey());
            $current = $eligible ? (clone $grants)->where('assignment_id', $assignment->getKey())
                ->where('assignment_version', $assignment->version)->where('share_revision', $share->revision)->first() : null;
            foreach ((clone $grants)->where('active', true)
                ->when($current, fn ($query) => $query->where('id', '!=', $current->getKey()))
                ->lockForUpdate()->get() as $previous) {
                $this->revokeGrant($previous, $share);
            }
            if (! $eligible) {
                return;
            }
            $grant = $current ?? DepartmentDisclosureGrant::query()->create([
                'share_id' => $share->getKey(), 'assignment_id' => $assignment->getKey(),
                'assignment_version' => $assignment->version, 'share_revision' => $share->revision,
                'recipient_id' => $assignment->assignee_id, 'active' => true,
            ]);
            $newAvailability = $grant->wasRecentlyCreated || ! $grant->active;
            if (! $grant->active) {
                $grant->forceFill(['active' => true, 'notified_at' => null])->save();
            }
            if ($newAvailability) {
                $this->auditGrant($share, $grant, $grant->wasRecentlyCreated
                    ? 'department_disclosure_access_granted' : 'department_disclosure_access_restored');
                $this->auditGrant($share, $grant, 'department_disclosure_delivery_requested');
            }
            if ($grant->notified_at === null) {
                $grantId = $grant->getKey();
                DB::afterCommit(function () use ($grantId) {
                    try {
                        DeliverAuthorityDepartmentDisclosure::dispatch($grantId);
                    } catch (\Throwable $exception) {
                        // The durable pending grant is retried by synchronization if queue delivery is unavailable.
                        report($exception);
                    }
                });
            }
        }, 3);
    }

    public function revokeGrant(DepartmentDisclosureGrant $grant, ?AuthorityDepartmentShare $share): void
    {
        if (! $grant->active) {
            return;
        }
        $grant->forceFill(['active' => false])->save();
        if ($share) {
            $this->auditGrant($share, $grant, 'department_disclosure_access_removed');
        }
    }

    public function auditGrant(AuthorityDepartmentShare $share, DepartmentDisclosureGrant $grant, string $action): void
    {
        $this->reviews->audit($share->document, null, $action, [
            'share_id' => $share->getKey(), 'department' => $share->department,
            'grant_id' => $grant->getKey(), 'assignment_id' => $grant->assignment_id,
            'recipient_id' => $grant->recipient_id, 'assignment_version' => $grant->assignment_version,
            'share_revision' => $grant->share_revision,
        ]);
    }

    public function eligible(AuthorityDepartmentShare $share): bool
    {
        $document = $share->document;

        return $share->status === 'published' && $share->published_at !== null && $share->redacted_verified_at !== null
            && pathinfo($share->file_name, PATHINFO_EXTENSION) === 'pdf'
            && $this->withinDates($share->effective_at, $share->expires_at)
            && $document && $this->sourceEligible($document);
    }

    private function sourceEligible(TeamAuthorityDocument $document, bool $checkDates = true): bool
    {
        return $document->review_decision === 'confirmed' && $document->lifecycle_status === 'active'
            && $document->status === 'approved'
            && $document->relationship && $document->relationship->ended_at === null
            && $document->effective_at !== null
            && (! $checkDates || (
                $document->effective_at->lessThanOrEqualTo(now())
                && ($document->expires_at === null || $document->expires_at->greaterThan(now()))
            ));
    }

    public function permission(string $department): string
    {
        return match ($department) {
            'medical-nursing' => 'authority_documents.healthcare.read_all',
            'finance-accounting' => 'authority_documents.financial.read_all',
            default => abort(404),
        };
    }

    public function canAccessDepartment(Model $actor, string $department): bool
    {
        return app(AccessControl::class)->isMember($actor, $department)
            && app(AccessControl::class)->allows($actor, $this->permission($department));
    }

    public function wardId(TeamAuthorityDocument $document): string
    {
        return (string) $document->relationship->self_advocate_id;
    }

    private function withinDates(mixed $effective, mixed $expires): bool
    {
        $timezone = config('app.timezone', 'UTC');
        $today = now($timezone)->toDateString();
        $date = fn ($value) => $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d') : CarbonImmutable::parse($value, $timezone)->toDateString();

        return ($effective === null || $date($effective) <= $today)
            && ($expires === null || $date($expires) >= $today);
    }

    private function assignmentIsLive(string $department, string $wardId, string $assigneeId): bool
    {
        return $department !== 'medical-nursing' || TeamMember::query()->where('self_advocate_id', $wardId)
            ->where('team_member_id', $assigneeId)->whereNotNull('nurse_assigned_by')->whereNull('ended_at')->exists();
    }

    private function validate(array $data, ?UploadedFile $file, bool $creating): array
    {
        $validated = Validator::make(array_merge($data, ['document' => $file]), [
            'department' => [$creating ? 'required' : 'sometimes', Rule::in(AuthorityDepartmentShare::DEPARTMENTS)],
            'summary' => ['required', 'string', 'max:5000'],
            'limitations' => ['required', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'effective_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($data['effective_at']), 'after_or_equal:effective_at')],
            'redacted_confirmation' => [$file ? 'accepted' : 'sometimes'],
            'document' => [$creating ? 'required' : 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ])->validate();
        if ($file && strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['document' => __('Only redacted PDF files are allowed.')]);
        }

        return $validated;
    }

    private function storeFile(UploadedFile $file): string
    {
        $path = $file->store('secure-access/authority-department-shares', 'local');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Redacted authority copy could not be stored.');
        }

        return $path;
    }
}
