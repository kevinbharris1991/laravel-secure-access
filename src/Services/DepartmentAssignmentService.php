<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\AuthorityDepartmentShare;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\SecureAccess;

class DepartmentAssignmentService
{
    public function authorize(Model $actor): void
    {
        $acl = app(AccessControl::class);
        abort_unless($acl->isMember($actor, 'administrative-director')
            && $acl->allows($actor, 'authority_documents.review_all'), 403);
    }

    public function eligibleOfficers(string $department): Builder
    {
        abort_unless(in_array($department, AuthorityDepartmentShare::DEPARTMENTS, true), 404);
        $query = SecureAccess::newUserQuery();
        $model = $query->getModel();

        return $query->whereExists(fn ($memberships) => $memberships->selectRaw('1')
            ->from('secure_access_memberships')
            ->join('secure_access_groups', 'secure_access_groups.id', '=', 'secure_access_memberships.group_id')
            ->where('secure_access_groups.key', $department)
            ->where('member_type', $model->getMorphClass())
            ->whereColumn('member_id', $model->qualifyColumn($model->getKeyName())));
    }

    public function assign(Model $actor, string $wardId, string $department, string $officerId): DepartmentAssignment
    {
        $this->authorize($actor);
        abort_unless(in_array($department, AuthorityDepartmentShare::DEPARTMENTS, true), 404);

        return DB::transaction(function () use ($actor, $wardId, $department, $officerId) {
            // Lock the ward, including the first assignment: a missing assignment row cannot serialize writers.
            $ward = SecureAccess::newUserQuery()->whereKey($wardId)->lockForUpdate()->first();
            $this->authorize($actor);
            if (! $ward || $ward->getAttribute('registration_role') !== 'self_advocate') {
                throw ValidationException::withMessages(['self_advocate_id' => __('Select an existing self advocate.')]);
            }
            $officer = $this->eligibleOfficers($department)->whereKey($officerId)->first();
            if (! $officer || $officerId === $wardId) {
                throw ValidationException::withMessages(['officer_id' => __('Select an existing department staff member.')]);
            }
            $assignment = DepartmentAssignment::query()->where('ward_id', $wardId)->where('department', $department)->lockForUpdate()->first();
            if ($assignment && $assignment->assignee_id === $officerId) {
                app(AuthorityDepartmentShareService::class)->synchronizeForWard($wardId);
                return $assignment;
            }
            $previous = $assignment?->assignee_id;
            $assignment ??= new DepartmentAssignment(['ward_id' => $wardId, 'department' => $department, 'version' => 0]);
            $assignment->fill([
                'assignee_id' => $officerId, 'assigned_by' => (string) $actor->getKey(), 'version' => $assignment->version + 1,
            ])->save();
            $this->audit($actor, $assignment, $previous, $previous === null ? 'assigned' : 'reassigned');
            app(AuthorityDepartmentShareService::class)->synchronizeForWard($wardId);

            return $assignment;
        }, 3);
    }

    public function remove(Model $actor, DepartmentAssignment $assignment): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $assignment) {
            SecureAccess::newUserQuery()->whereKey($assignment->ward_id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $assignment = DepartmentAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            if ($assignment->assignee_id === null) {
                return;
            }
            $previous = $assignment->assignee_id;
            $assignment->fill([
                'assignee_id' => null, 'assigned_by' => (string) $actor->getKey(), 'version' => $assignment->version + 1,
            ])->save();
            $this->audit($actor, $assignment, $previous, 'removed');
            app(AuthorityDepartmentShareService::class)->synchronizeForWard($assignment->ward_id);
        }, 3);
    }

    private function audit(Model $actor, DepartmentAssignment $assignment, ?string $previous, string $action): void
    {
        DB::table('secure_access_department_assignment_audits')->insert([
            'assignment_id' => $assignment->getKey(), 'actor_id' => (string) $actor->getKey(),
            'previous_assignee_id' => $previous, 'assignee_id' => $assignment->assignee_id,
            'action' => $action, 'created_at' => now(),
        ]);
    }
}
