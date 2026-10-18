<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;

class AuthorityDepartmentShareService
{
    public function __construct(protected AuthorityReviewService $reviews) {}

    public function canShare(Model $actor, TeamAuthorityDocument $document): bool
    {
        return $this->reviews->canReview($actor, $document) && $this->isCurrentApproval($document);
    }

    public function canRead(Model $actor, AuthorityDepartmentShare $share): bool
    {
        return app(AccessControl::class)->allows($actor, $this->permission($share->department))
            && $this->isCurrentApproval($share->document);
    }

    public function share(Model $actor, TeamAuthorityDocument $document, array $data, UploadedFile $file): AuthorityDepartmentShare
    {
        $data = Validator::make(array_merge($data, ['document' => $file]), [
            'department' => ['required', Rule::in(AuthorityDepartmentShare::DEPARTMENTS)],
            'summary' => ['required', 'string', 'max:5000'],
            'limitations' => ['required', 'string', 'max:5000'],
            'redacted_confirmation' => ['accepted'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ])->validate();

        abort_unless($this->reviews->canReview($actor, $document), 403);
        $document->loadMissing('relationship');
        $this->reviews->expire($document->relationship, $actor);

        $path = null;
        try {
            return DB::transaction(function () use ($actor, $document, $data, $file, &$path) {
                $relationship = TeamMember::query()->whereKey($document->team_member_id)->lockForUpdate()->firstOrFail();
                $document = TeamAuthorityDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                $document->setRelation('relationship', $relationship);
                $document->refresh();
                $document->setRelation('relationship', $relationship);
                abort_unless($this->canShare($actor, $document), 403);

                $path = $file->store('secure-access/authority-department-shares', 'local');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Redacted authority copy could not be stored.');
                }

                $share = AuthorityDepartmentShare::query()->create([
                    'document_id' => $document->getKey(),
                    'department' => $data['department'],
                    'created_by' => (string) $actor->getKey(),
                    'summary' => $data['summary'],
                    'limitations' => $data['limitations'],
                    'path' => $path,
                    'file_name' => 'redacted-authority-document.'.$file->extension(),
                ]);
                $this->reviews->audit($document, $actor, 'department_share_created', [
                    'share_id' => $share->getKey(), 'department' => $share->department,
                ]);

                return $share;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function notifyDepartment(AuthorityDepartmentShare $share): void
    {
        $group = AccessGroup::query()->where('key', $share->department)->first();
        if (! $group) {
            return;
        }

        $userModel = SecureAccess::userModel();
        $memberIds = DB::table('secure_access_memberships')
            ->where('group_id', $group->getKey())
            ->where('member_type', (new $userModel)->getMorphClass())
            ->pluck('member_id');
        if ($memberIds->isEmpty()) {
            return;
        }

        foreach (SecureAccess::newUserQuery()->whereKey($memberIds)->get() as $recipient) {
            $email = $recipient->getAttribute('email');
            if (is_string($email) && $email !== '') {
                \Illuminate\Support\Facades\Notification::route('mail', $email)
                    ->notify(new \SecureAccess\Notifications\AuthorityDepartmentShareNotification($share->department));
            }
        }
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
        return app(AccessControl::class)->allows($actor, $this->permission($department));
    }

    protected function isCurrentApproval(TeamAuthorityDocument $document): bool
    {
        $document->loadMissing('relationship');

        return $document->status === 'approved'
            && $document->relationship->ended_at === null
            && ($document->effective_at === null || $document->effective_at->lessThanOrEqualTo(now()))
            && ($document->expires_at === null || $document->expires_at->greaterThan(now()));
    }
}
