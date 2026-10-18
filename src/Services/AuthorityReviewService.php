<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Authorization\RepresentativeAccess;
use SecureAccess\Models\AuthorityDocumentAudit;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;

class AuthorityReviewService
{
    public function isReviewer(Model $user): bool
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
        $path = null;
        try {
            return DB::transaction(function () use ($user, $relationship, $data, $file, &$path) {
                $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($relationship->ended_at === null
                    && $relationship->team_member_id === (string) $user->getKey()
                    && $relationship->team_member_role === $user->getAttribute('registration_role')
                    && in_array($relationship->team_member_role, ['professional', 'family_caregiver'], true), 404);

                $previous = null;
                $latest = $relationship->authorityDocuments()->where('type', $data['type'])->latest('id')->lockForUpdate()->first();
                if (empty($data['previous_document_id']) && $latest
                    && in_array($latest->status, ['rejected', 'needs_information'], true)) {
                    throw ValidationException::withMessages([
                        'previous_document_id' => __('Resubmit the reviewed document as a new version.'),
                    ]);
                }
                if (! empty($data['previous_document_id'])) {
                    $previous = TeamAuthorityDocument::query()->whereKey($data['previous_document_id'])->lockForUpdate()->first();
                    if (! $previous || (string) $previous->team_member_id !== (string) $relationship->getKey()
                        || $previous->type !== $data['type']
                        || ! in_array($previous->status, ['rejected', 'needs_information'], true)
                        || TeamAuthorityDocument::query()->where('previous_document_id', $previous->getKey())->exists()) {
                        throw ValidationException::withMessages(['previous_document_id' => __('Select an eligible document to resubmit.')]);
                    }
                }

                $path = $file->store('secure-access/authority-documents', 'local');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Authority evidence could not be stored.');
                }
                $document = TeamAuthorityDocument::query()->create([
                    'team_member_id' => $relationship->getKey(),
                    'type' => $data['type'],
                    'path' => $path,
                    'file_name' => 'authority-document.'.$file->extension(),
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
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function review(Model $user, TeamAuthorityDocument $document, array $data): void
    {
        $data = Validator::make($data, [
            'decision' => ['required', Rule::in(['approved', 'rejected', 'needs_information'])],
            'comments' => ['required', 'string', 'max:10000'],
            'approved_scopes' => ['required_if:decision,approved', 'array'],
            'approved_scopes.*' => ['string', 'distinct'],
            'effective_at' => ['required_if:decision,approved', 'nullable', 'date'],
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
                || $document->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => __('This document cannot receive that decision.')]);
            }
            if (trim($data['comments'] ?? '') === '') {
                throw ValidationException::withMessages(['comments' => __('Review comments are required.')]);
            }
            $updates = [];
            if ($decision === 'approved') {
                $allowed = array_merge([], ...array_values(app(RepresentativeAccess::class)->capabilities()));
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
                $effectiveAt = \Illuminate\Support\Carbon::parse($data['effective_at']);
                $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== ''
                    ? \Illuminate\Support\Carbon::parse($data['expires_at']) : null;
                if ($expiresAt && ($expiresAt->lessThanOrEqualTo($effectiveAt) || $expiresAt->lessThanOrEqualTo(now()))) {
                    throw ValidationException::withMessages(['expires_at' => __('Expiry must be after the effective date and in the future.')]);
                }
                $priorApprovals = $relationship->authorityDocuments()->where('type', $document->type)
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
                    $prior->forceFill(['status' => 'superseded'])->save();
                    $this->audit($prior, $user, 'superseded', ['from_status' => 'approved', 'to_status' => 'superseded']);
                }
                $updates = ['approved_scopes' => $scopes, 'effective_at' => $effectiveAt, 'expires_at' => $expiresAt];
            }
            $history = $document->review_history ?? [];
            $history[] = ['decision' => $decision, 'comments' => $data['comments'],
                'reviewed_by' => (string) $user->getKey(), 'reviewed_at' => now()->toIso8601String()];
            $from = $document->status;
            $document->forceFill($updates + [
                'status' => $decision, 'reviewed_by' => (string) $user->getKey(),
                'reviewed_at' => now(), 'review_history' => $history,
            ])->save();
            $this->audit($document, $user, 'reviewed', ['from_status' => $from, 'to_status' => $decision]);
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
        foreach ($relationship->authorityDocuments()->where('status', 'approved')->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())->lockForUpdate()->get() as $document) {
            $document->forceFill(['status' => 'expired'])->save();
            $this->audit($document, $actor, 'expired', ['from_status' => 'approved', 'to_status' => 'expired']);
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
            foreach ($relationship->authorityDocuments()->whereIn('status', ['approved', 'pending', 'needs_information'])->lockForUpdate()->get() as $document) {
                $from = $document->status;
                $document->forceFill(['status' => 'suspended', 'termination_basis' => 'relationship_ended'])->save();
                $this->audit($document, $user, 'relationship_ended', ['from_status' => $from, 'to_status' => 'suspended']);
            }
            AuthorityDocumentAudit::query()->create([
                'team_member_id' => $relationship->getKey(), 'actor_id' => (string) $user->getKey(),
                'action' => 'relationship_ended', 'metadata' => [],
            ]);
        });
    }

    public function audit(TeamAuthorityDocument $document, ?Model $actor, string $action, array $metadata = []): void
    {
        AuthorityDocumentAudit::query()->create([
            'document_id' => $document->getKey(), 'team_member_id' => $document->team_member_id,
            'actor_id' => $actor ? (string) $actor->getKey() : null, 'action' => $action,
            'metadata' => array_intersect_key($metadata, array_flip(['from_status', 'to_status', 'evidence_id'])),
        ]);
    }
}
