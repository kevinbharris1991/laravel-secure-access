<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\DepartmentAssignment;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;

class NurseTeamService
{
    public function authorize(Model $actor): void
    {
        app(DepartmentAssignmentService::class)->authorize($actor);
    }

    public function eligibleNurses(): Builder
    {
        $query = SecureAccess::newUserQuery();
        $model = $query->getModel();

        return $query->whereExists(fn ($memberships) => $memberships->selectRaw('1')
            ->from('secure_access_memberships')
            ->join('secure_access_groups', 'secure_access_groups.id', '=', 'secure_access_memberships.group_id')
            ->where('secure_access_groups.key', 'medical-nursing')
            ->where('member_type', $model->getMorphClass())
            ->whereColumn('member_id', $model->qualifyColumn($model->getKeyName())));
    }

    public function assign(Model $actor, string $adultId, string $nurseId): TeamMember
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $adultId, $nurseId) {
            $users = SecureAccess::newUserQuery()->whereKey(array_unique([
                (string) $actor->getKey(), $adultId, $nurseId,
            ]))->orderBy(SecureAccess::newUserQuery()->getModel()->getKeyName())->lockForUpdate()->get();
            $actor = $users->first(fn ($user) => (string) $user->getKey() === (string) $actor->getKey());
            abort_unless($actor, 403);
            $this->lockMembership($actor, 'administrative-director');
            $this->authorize($actor);
            $adult = $users->first(fn ($user) => (string) $user->getKey() === $adultId);
            $nurse = $users->first(fn ($user) => (string) $user->getKey() === $nurseId);
            if (! $adult || $adult->getAttribute('registration_role') !== 'self_advocate') {
                throw ValidationException::withMessages(['self_advocate_id' => __('Select an existing self advocate.')]);
            }
            if (! $nurse || $adultId === $nurseId) {
                throw ValidationException::withMessages(['nurse_id' => __('Select an existing nursing staff member.')]);
            }
            $this->lockMembership($nurse, 'medical-nursing');
            if (! app(AccessControl::class)->isMember($nurse, 'medical-nursing')) {
                throw ValidationException::withMessages(['nurse_id' => __('Select an existing nursing staff member.')]);
            }

            $relationship = TeamMember::query()->where('self_advocate_id', $adultId)
                ->where('team_member_id', $nurseId)->lockForUpdate()->first();
            if ($relationship && $relationship->ended_at === null
                && $relationship->nurse_assigned_by === (string) $actor->getKey()) {
                $current = DepartmentAssignment::query()->where('ward_id', $adultId)
                    ->where('department', 'medical-nursing')->value('assignee_id');
                app(DepartmentAssignmentService::class)->assign($actor, $adultId, 'medical-nursing', $nurseId);
                if ($current !== $nurseId) {
                    $this->audit($actor, $relationship, 'assigned');
                }
                return $relationship;
            }
            if (! $relationship) {
                $relationship = new TeamMember([
                    'self_advocate_id' => $adultId, 'team_member_id' => $nurseId, 'team_member_role' => 'nurse',
                ]);
            } elseif ($relationship->ended_at !== null) {
                $relationship->forceFill(['ended_at' => null, 'team_member_role' => 'nurse']);
            }
            $relationship->forceFill([
                'nurse_assigned_by' => (string) $actor->getKey(), 'nurse_assigned_at' => now(),
            ])->save();
            $this->audit($actor, $relationship, 'assigned');
            app(DepartmentAssignmentService::class)->assign($actor, $adultId, 'medical-nursing', $nurseId);

            return $relationship;
        }, 3);
    }

    public function remove(Model $actor, TeamMember $relationship): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $relationship) {
            $actor = SecureAccess::newUserQuery()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->lockMembership($actor, 'administrative-director');
            $this->authorize($actor);
            $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($relationship->nurse_assigned_by !== null, 404);
            if ($relationship->ended_at !== null) {
                return;
            }
            app(AuthorityReviewService::class)->endRelationship($actor, $relationship);
            $this->audit($actor, $relationship, 'removed');
            $assignment = DepartmentAssignment::query()->where('ward_id', $relationship->self_advocate_id)
                ->where('department', 'medical-nursing')->where('assignee_id', $relationship->team_member_id)->first();
            if ($assignment) {
                app(DepartmentAssignmentService::class)->remove($actor, $assignment);
            }
        }, 3);
    }

    private function lockMembership(Model $user, string $group): void
    {
        $groupIds = DB::table('secure_access_groups')->where('key', $group)->lockForUpdate()->pluck('id');
        DB::table('secure_access_memberships')->where('member_type', $user->getMorphClass())
            ->where('member_id', (string) $user->getKey())
            ->whereIn('group_id', $groupIds)
            ->lockForUpdate()->get();
    }

    private function audit(Model $actor, TeamMember $relationship, string $action): void
    {
        DB::table('secure_access_nursing_assignment_audits')->insert([
            'actor_id' => (string) $actor->getKey(), 'team_member_id' => $relationship->getKey(),
            'action' => $action, 'created_at' => now(),
        ]);
    }
}
