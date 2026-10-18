<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\AuthorityRevocationEvidence;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;

class AuthorityRevocationEvidenceService
{
    public function __construct(protected AuthorityReviewService $reviews) {}

    public function canReview(Model $actor, AuthorityRevocationEvidence $evidence): bool
    {
        return $this->reviews->isAdministrativeDirector($actor)
            && $this->reviews->canReview($actor, $evidence->document)
            && (string) $actor->getKey() !== $evidence->submitted_by;
    }

    public function submit(Model $actor, TeamAuthorityDocument $document, array $data, UploadedFile $file): AuthorityRevocationEvidence
    {
        abort_unless($this->reviews->canView($actor, $document), 404);
        $data = Validator::make(array_merge($data, ['document' => $file]), [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'comments' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $document, $data, $file, &$path) {
                $relationship = TeamMember::query()->whereKey($document->team_member_id)->lockForUpdate()->firstOrFail();
                $document = TeamAuthorityDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                $document->setRelation('relationship', $relationship);
                abort_unless($this->reviews->canView($actor, $document), 404);
                $path = $file->store('secure-access/revocation-evidence', 'local');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Revocation evidence could not be stored.');
                }
                $evidence = AuthorityRevocationEvidence::query()->create([
                    'document_id' => $document->getKey(), 'submitted_by' => (string) $actor->getKey(),
                    'status' => 'pending', 'path' => $path,
                    'file_name' => 'revocation-evidence.'.$file->extension(),
                    'comments' => $data['comments'] ?? null,
                ]);
                $this->audit($evidence, $actor, 'revocation_evidence_submitted');

                return $evidence;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function review(Model $actor, AuthorityRevocationEvidence $evidence, array $data): void
    {
        abort_unless($this->canReview($actor, $evidence), 403);
        $data = Validator::make($data, [
            'decision' => ['required', Rule::in(['accepted', 'rejected', 'needs_information'])],
            'comments' => ['required', 'string', 'max:10000'],
        ])->validate();
        if (trim($data['comments']) === '') {
            throw ValidationException::withMessages(['comments' => __('Review comments are required.')]);
        }
        DB::transaction(function () use ($actor, $evidence, $data) {
            $document = $evidence->document;
            $relationship = TeamMember::query()->whereKey($document->team_member_id)->lockForUpdate()->firstOrFail();
            $document = TeamAuthorityDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $document->setRelation('relationship', $relationship);
            $evidence = AuthorityRevocationEvidence::query()->whereKey($evidence->getKey())->lockForUpdate()->firstOrFail();
            $evidence->setRelation('document', $document);
            abort_unless($this->canReview($actor, $evidence), 403);
            if ($evidence->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => __('Submit new evidence for an additional review.')]);
            }
            $history = $evidence->review_history ?? [];
            $history[] = [
                'decision' => $data['decision'], 'comments' => $data['comments'],
                'reviewed_by' => (string) $actor->getKey(), 'reviewed_at' => now()->toIso8601String(),
            ];
            $evidence->forceFill([
                'status' => $data['decision'], 'reviewed_by' => (string) $actor->getKey(),
                'reviewed_at' => now(), 'review_history' => $history,
            ])->save();
            $this->audit($evidence, $actor, 'revocation_evidence_reviewed', [
                'from_status' => 'pending', 'to_status' => $data['decision'],
            ]);
            if ($data['decision'] === 'accepted') {
                $from = $document->status;
                $document->forceFill(['status' => 'revoked', 'termination_basis' => 'verified_evidence'])->save();
                $this->audit($evidence, $actor, 'verified_termination_recorded', [
                    'from_status' => $from, 'to_status' => 'revoked',
                ]);
            }
        });
    }

    public function audit(AuthorityRevocationEvidence $evidence, Model $actor, string $action, array $metadata = []): void
    {
        $this->reviews->audit($evidence->document, $actor, $action, ['evidence_id' => $evidence->getKey()] + $metadata);
    }
}
