<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\Notifications\AuthorityReviewNotification;
use SecureAccess\SecureAccess;

class AuthorityReviewService
{
    public function capabilities(): array
    {
        return array_merge(RepresentativeAccess::capabilities(), [
            'financial_application_decisions' => [
                'financial_aid.revoke', 'financial_aid.reinstate', 'tuition.revoke', 'tuition.reinstate',
            ],
        ]);
    }

    public function isReviewer(Model $user): bool
    {
        return $this->isAdministrativeDirector($user)
            && app(AccessControl::class)->allows($user, 'authority_documents.review_all');
    }

    public function isAdministrativeDirector(Model $user): bool
    {
        return app(AccessControl::class)->isMember($user, 'administrative-director');
    }

    public function canReview(Model $user, TeamAuthorityDocument $document): bool
    {
        $relationship = $document->relationship;

        return $this->isReviewer($user) && ! in_array((string) $user->getKey(), [
            $document->submitted_by, $relationship->team_member_id, $relationship->self_advocate_id,
        ], true);
    }

    public function canView(Model $user, TeamAuthorityDocument $document): bool
    {
        return $this->isReviewer($user) || in_array((string) $user->getKey(), [
            $document->relationship->team_member_id, $document->relationship->self_advocate_id,
        ], true);
    }

    public function submit(Model $user, TeamMember $relationship, array $data, UploadedFile $file): TeamAuthorityDocument
    {
        $storage = app(AuthorityOriginalStorage::class);
        $storage->validate($file);
        Validator::make($data, ['type' => ['required', Rule::in(TeamAuthorityDocument::TYPES)]])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($user, $relationship, $data, $file, $storage, &$path) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $users = SecureAccess::newUserQuery()->getModel();
                    $key = $users->getKeyName();
                    DB::table($users->getTable())->where($key, $relationship->self_advocate_id)
                        ->update([$key => DB::raw(DB::connection()->getQueryGrammar()->wrap($key))]);
                }
                $ward = SecureAccess::newUserQuery()->whereKey($relationship->self_advocate_id)->lockForUpdate()->firstOrFail();
                $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($relationship->ended_at === null
                    && $relationship->team_member_id === (string) $user->getKey()
                    && $relationship->team_member_role === $user->getAttribute('registration_role')
                    && in_array($relationship->team_member_role, ['professional', 'family_caregiver'], true), 404);

                $previous = null;
                $latest = $relationship->authorityDocuments()->where('type', $data['type'])->latest('id')->lockForUpdate()->first();
                if (empty($data['previous_document_id']) && $latest
                    && in_array($latest->review_decision, ['denied', 'inquired'], true)
                    && in_array($latest->lifecycle_status, ['active', 'inactive'], true)) {
                    throw ValidationException::withMessages([
                        'previous_document_id' => __('Resubmit the reviewed document as a new version.'),
                    ]);
                }
                if (! empty($data['previous_document_id'])) {
                    $previous = TeamAuthorityDocument::query()->whereKey($data['previous_document_id'])->lockForUpdate()->first();
                    if (! $previous || (string) $previous->team_member_id !== (string) $relationship->getKey()
                        || $previous->type !== $data['type']
                        || ! in_array($previous->review_decision, ['denied', 'inquired'], true)
                        || ! in_array($previous->lifecycle_status, ['active', 'inactive'], true)
                        || TeamAuthorityDocument::query()->where('previous_document_id', $previous->getKey())->exists()) {
                        throw ValidationException::withMessages(['previous_document_id' => __('Select an eligible document to resubmit.')]);
                    }
                }

                $revision = (int) TeamAuthorityDocument::query()->where('ward_id', (string) $ward->getKey())
                    ->where('type', $data['type'])->max('revision') + 1;
                $filename = $storage->filename($user, $ward, $data['type'], $revision, now(), strtolower($file->getClientOriginalExtension()));
                $candidate = $storage->path((string) $ward->getKey(), $data['type'], $filename);
                $storage->store($file, $candidate);
                $path = $candidate;
                $document = TeamAuthorityDocument::query()->create([
                    'team_member_id' => $relationship->getKey(),
                    'ward_id' => (string) $ward->getKey(),
                    'revision' => $revision,
                    'type' => $data['type'],
                    'path' => $path,
                    'file_name' => $filename,
                    'status' => 'pending',
                    'submitted_by' => (string) $user->getKey(),
                    'previous_document_id' => $previous?->getKey(),
                ]);
                $this->audit($document, $user, 'submitted');
                if ($previous) {
                    $this->audit($document, $user, 'resubmitted');
                }

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                $storage->disk()->delete($path);
            }
            throw $exception;
        }
    }

    public function review(Model $user, TeamAuthorityDocument $document, array $data): void
    {
        $data['decision'] = match ($data['decision'] ?? null) {
            'confirm', 'approved' => 'confirmed',
            'deny', 'rejected' => 'denied',
            'inquire', 'needs_information' => 'inquired',
            default => $data['decision'] ?? null,
        };
        $data['comments'] = $data['reason'] ?? $data['comments'] ?? null;
        $data = Validator::make($data, [
            'decision' => ['required', Rule::in(['confirmed', 'denied', 'inquired'])],
            'comments' => ['required', 'string', 'max:10000'],
            'approved_scopes' => ['required_if:decision,confirmed', 'array'],
            'approved_scopes.*' => ['string', 'distinct'],
            'effective_at' => ['required_if:decision,confirmed', 'nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();
        DB::transaction(function () use ($user, $document, $data) {
            $relationship = TeamMember::query()->whereKey($document->team_member_id)->lockForUpdate()->firstOrFail();
            $document = TeamAuthorityDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $document->setRelation('relationship', $relationship);
            abort_unless($this->canReview($user, $document), 403);
            $this->expireLocked($relationship, $user);
            $document->refresh();
            $decision = $data['decision'];
            if ($relationship->ended_at !== null
                || ! in_array($document->lifecycle_status, ['active', 'inactive'], true)
                || $document->review_decision === 'denied'
                || ($document->review_decision === 'confirmed' && $decision === 'confirmed')) {
                throw ValidationException::withMessages(['decision' => __('This document cannot receive that decision.')]);
            }
            if (trim($data['comments'] ?? '') === '') {
                throw ValidationException::withMessages(['comments' => __('Review comments are required.')]);
            }
            $updates = [];
            if ($decision === 'confirmed') {
                $allowed = array_merge([], ...array_values($this->capabilities()));
                $scopes = array_values(array_unique($data['approved_scopes'] ?? []));
                if (! $scopes || array_diff($scopes, $allowed)) {
                    throw ValidationException::withMessages(['approved_scopes' => __('Select valid capabilities.')]);
                }
                foreach ($scopes as $scope) {
                    [$module, $action] = explode('.', $scope, 2);
                    if (in_array($action, ['update', 'pay', 'cancel', 'submit', 'commit'], true)
                        && ! in_array($module.'.read', $scopes, true)) {
                        throw ValidationException::withMessages([
                            'approved_scopes' => __('Record actions require selecting the same module’s read capability. No capabilities are added automatically.'),
                        ]);
                    }
                }
                $effectiveAt = $this->authorityDate($data['effective_at']);
                $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== ''
                    ? $this->authorityDate($data['expires_at'], true) : null;
                if ($expiresAt && ($expiresAt->lessThanOrEqualTo($effectiveAt) || $expiresAt->lessThanOrEqualTo(now()))) {
                    throw ValidationException::withMessages(['expires_at' => __('Expiry must be after the effective date and in the future.')]);
                }
                $priorApprovals = $relationship->authorityDocuments()->where('type', $document->type)
                    ->whereKeyNot($document->getKey())
                    ->where('review_decision', 'confirmed')->where('lifecycle_status', 'active')
                    ->when($document->lifecycle_status !== 'active', fn ($query) => $query->whereRaw('1 = 0'))
                    ->where('status', 'approved')->lockForUpdate()->get();
                if ($effectiveAt->greaterThan(now()) && $priorApprovals->contains(fn ($prior) =>
                    ($prior->effective_at === null || $prior->effective_at->lessThanOrEqualTo(now()))
                    && ($prior->expires_at === null || $prior->expires_at->greaterThan(now())))) {
                    throw ValidationException::withMessages([
                        'effective_at' => __('A replacement for active authority must take effect immediately to avoid interrupting access.'),
                    ]);
                }
                // A failed replacement never changes an existing approval.
                foreach ($priorApprovals as $prior) {
                    $history = $prior->review_history ?? [];
                    $history[] = [
                        'decision' => 'superseded', 'comments' => $data['comments'],
                        'review_decision' => $prior->review_decision, 'lifecycle_status' => 'superseded',
                        'reviewed_by' => (string) $user->getKey(), 'reviewed_at' => now()->toIso8601String(),
                        'approved_scopes' => $prior->approved_scopes ?? [],
                        'effective_at' => $prior->effective_at?->toIso8601String(),
                        'expires_at' => $prior->expires_at?->toIso8601String(),
                    ];
                    $prior->forceFill(['status' => 'superseded', 'lifecycle_status' => 'superseded', 'review_history' => $history])->save();
                    $this->audit($prior, $user, 'superseded', ['from_status' => 'approved', 'to_status' => 'superseded']);
                    app(AuthorityDepartmentShareService::class)->synchronizeForDocument($prior);
                }
                $updates = ['approved_scopes' => $scopes, 'effective_at' => $effectiveAt, 'expires_at' => $expiresAt];
            }
            $history = $document->review_history ?? [];
            $history[] = ['decision' => $decision, 'comments' => $data['comments'],
                'review_decision' => $decision, 'lifecycle_status' => $document->lifecycle_status,
                'reviewed_by' => (string) $user->getKey(), 'reviewed_at' => now()->toIso8601String(),
                'approved_scopes' => $decision === 'confirmed' ? ($updates['approved_scopes'] ?? []) : ($document->approved_scopes ?? []),
                'confirmed_capabilities' => $decision === 'confirmed' ? ($updates['approved_scopes'] ?? []) : [],
                'effective_at' => $decision === 'confirmed' ? $updates['effective_at']->toIso8601String() : $document->effective_at?->toIso8601String(),
                'expires_at' => $decision === 'confirmed' ? $updates['expires_at']?->toIso8601String() : $document->expires_at?->toIso8601String()];
            $from = $document->status;
            $document->review_decision = $decision;
            $document->forceFill($updates + [
                'status' => $document->compatibilityStatus(), 'reviewed_by' => (string) $user->getKey(),
                'reviewed_at' => now(), 'review_history' => $history,
            ])->save();
            $this->audit($document, $user, 'reviewed', ['from_status' => $from, 'to_status' => $document->status]);
            app(AuthorityDepartmentShareService::class)->synchronizeForDocument($document);
        });

        $reviewed = TeamAuthorityDocument::query()->with('relationship')->findOrFail($document->getKey());
        $recipientIds = array_unique(array_filter([
            $reviewed->submitted_by,
            $reviewed->relationship->team_member_id,
            $reviewed->relationship->self_advocate_id,
        ]));
        foreach (SecureAccess::newUserQuery()->whereKey($recipientIds)->get() as $recipient) {
            if ((string) $recipient->getKey() === (string) $user->getKey()) {
                continue;
            }
            $email = $recipient->getAttribute('email');
            if (is_string($email) && $email !== '') {
                Notification::route('mail', $email)->notify(new AuthorityReviewNotification);
            }
        }
    }

    protected function authorityDate(string $value, bool $inclusiveEnd = false): \Illuminate\Support\Carbon
    {
        $date = \Illuminate\Support\Carbon::parse($value, config('app.timezone', 'UTC'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date->startOfDay();
            if ($inclusiveEnd) {
                $date->addDay();
            }
        }

        return $date->setTimezone(config('app.timezone', 'UTC'));
    }

    public function setLifecycle(Model $user, TeamAuthorityDocument $document, array $data): void
    {
        $data['comments'] = $data['reason'] ?? $data['comments'] ?? null;
        $data = Validator::make($data, [
            'lifecycle_status' => ['required', Rule::in(['active', 'inactive', 'superseded'])],
            'comments' => ['required', 'string', 'max:10000'],
        ])->validate();
        if (trim($data['comments']) === '') {
            throw ValidationException::withMessages(['comments' => __('A reason is required.')]);
        }
        DB::transaction(function () use ($user, $document, $data) {
            $relationship = TeamMember::query()->whereKey($document->team_member_id)->lockForUpdate()->firstOrFail();
            $document = TeamAuthorityDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $document->setRelation('relationship', $relationship);
            abort_unless($this->canReview($user, $document), 403);
            $this->expireLocked($relationship, $user);
            $document->refresh();
            if ($relationship->ended_at !== null || ! in_array($document->lifecycle_status, ['active', 'inactive'], true)
                || ($data['lifecycle_status'] === 'active' && $document->expires_at?->lessThanOrEqualTo(now()))) {
                throw ValidationException::withMessages(['lifecycle_status' => __('This document cannot receive that lifecycle change.')]);
            }
            $from = $document->status;
            $history = $document->review_history ?? [];
            $history[] = [
                'decision' => $data['lifecycle_status'], 'review_decision' => $document->review_decision,
                'lifecycle_status' => $data['lifecycle_status'], 'comments' => $data['comments'],
                'reviewed_by' => (string) $user->getKey(), 'reviewed_at' => now()->toIso8601String(),
                'approved_scopes' => $document->approved_scopes ?? [],
            ];
            $document->lifecycle_status = $data['lifecycle_status'];
            $document->forceFill(['status' => $document->compatibilityStatus(), 'review_history' => $history])->save();
            $this->audit($document, $user, 'lifecycle_changed', ['from_status' => $from, 'to_status' => $document->status]);
            app(AuthorityDepartmentShareService::class)->synchronizeForDocument($document);
        });
    }

    public function expire(TeamMember $relationship, ?Model $actor = null): void
    {
        DB::transaction(function () use ($relationship, $actor) {
            $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
            $this->expireLocked($relationship, $actor);
        });
    }

    protected function expireLocked(TeamMember $relationship, ?Model $actor): void
    {
        foreach ($relationship->authorityDocuments()->whereIn('lifecycle_status', ['active', 'inactive'])->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())->lockForUpdate()->get() as $document) {
            $from = $document->status;
            $document->forceFill(['status' => 'expired', 'lifecycle_status' => 'expired'])->save();
            $this->audit($document, $actor, 'expired', ['from_status' => $from, 'to_status' => 'expired']);
            app(AuthorityDepartmentShareService::class)->synchronizeForDocument($document);
        }
    }

    public function endRelationship(Model $user, TeamMember $relationship): void
    {
        DB::transaction(function () use ($user, $relationship) {
            $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array((string) $user->getKey(), [$relationship->self_advocate_id, $relationship->team_member_id], true)
                    || ($relationship->getAttribute('nurse_assigned_by') !== null && $this->isReviewer($user)),
                404
            );
            if ($relationship->ended_at !== null) {
                return;
            }
            $this->expireLocked($relationship, $user);
            $relationship->forceFill(['ended_at' => now()])->save();
            foreach ($relationship->authorityDocuments()->whereIn('lifecycle_status', ['active', 'inactive'])->lockForUpdate()->get() as $document) {
                $from = $document->status;
                $document->forceFill(['status' => 'suspended', 'lifecycle_status' => 'suspended'])->save();
                $this->audit($document, $user, 'relationship_ended', ['from_status' => $from, 'to_status' => 'suspended']);
                app(AuthorityDepartmentShareService::class)->synchronizeForDocument($document);
            }
            AuthorityDocumentAudit::query()->create([
                'team_member_id' => $relationship->getKey(), 'actor_id' => (string) $user->getKey(),
                'action' => 'relationship_ended', 'metadata' => [],
            ]);
            app(AuthorityDepartmentShareService::class)->synchronizeForWard((string) $relationship->self_advocate_id);
        });
    }

    public function audit(TeamAuthorityDocument $document, ?Model $actor, string $action, array $metadata = []): void
    {
        AuthorityDocumentAudit::query()->create([
            'document_id' => $document->getKey(), 'team_member_id' => $document->team_member_id,
            'actor_id' => $actor ? (string) $actor->getKey() : null, 'action' => $action,
            'metadata' => array_intersect_key($metadata, array_flip([
                'from_status', 'to_status', 'evidence_id', 'share_id', 'department',
                'grant_id', 'assignment_id', 'recipient_id', 'assignment_version', 'share_revision',
            ])),
        ]);
    }
}
