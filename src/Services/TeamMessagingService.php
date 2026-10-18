<?php

namespace SecureAccess\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\TeamConversation;
use SecureAccess\Models\TeamMember;
use SecureAccess\Models\TeamMessage;
use SecureAccess\SecureAccess;

class TeamMessagingService
{
    public function canUseOverlay(?Authenticatable $user): bool
    {
        return $user instanceof Model && (
            in_array($user->getAttribute('registration_role'), ['self_advocate', 'family_caregiver', 'professional'], true)
            || app(AccessControl::class)->isMember($user, 'medical-nursing')
            || app(AccessControl::class)->isMember($user, 'administrative-director')
        );
    }

    private function nurseAssignments(): Builder
    {
        $query = TeamMember::query()->whereNull('ended_at')
            ->whereNotNull('nurse_assigned_by')->whereNotNull('nurse_assigned_at');

        if (! config('secure-access-acl.enabled')) {
            return $query->whereRaw('1 = 0');
        }

        $users = SecureAccess::newUserQuery();
        $model = $users->getModel();

        return $query->whereIn('team_member_id', $users->select($model->getQualifiedKeyName()))
            ->whereIn('team_member_id', DB::table('secure_access_memberships')
                ->join('secure_access_groups', 'secure_access_groups.id', '=', 'secure_access_memberships.group_id')
                ->where('secure_access_groups.key', 'medical-nursing')
                ->where('secure_access_memberships.member_type', $model->getMorphClass())
                ->select('secure_access_memberships.member_id'));
    }

    private function activeMemberships(): Builder
    {
        $users = SecureAccess::newUserQuery();
        $userModel = $users->getModel();

        return TeamMember::query()->whereNull('ended_at')->where(function (Builder $query) use ($users, $userModel) {
            $query->whereExists($users->selectRaw('1')
                ->whereColumn($userModel->getQualifiedKeyName(), 'secure_access_team_members.team_member_id')
                ->whereColumn($userModel->qualifyColumn('registration_role'), 'secure_access_team_members.team_member_role')
                ->toBase())
                ->orWhereIn('id', $this->nurseAssignments()->select('id'));
        });
    }

    public function teamIds(string $actor): Collection
    {
        $ids = $this->activeMemberships()->where('team_member_id', $actor)
            ->pluck('self_advocate_id');
        $user = SecureAccess::newUserQuery()->find($actor);

        if ($user?->getAttribute('registration_role') === 'self_advocate') {
            $ids->push($actor);
        }

        if (app(AccessControl::class)->isMember($user, 'administrative-director')) {
            $ids = $ids->merge($this->nurseAssignments()->where('nurse_assigned_by', $actor)->pluck('self_advocate_id'));
        }

        return SecureAccess::newUserQuery()->whereKey($ids->unique()->all())
            ->where('registration_role', 'self_advocate')->get()
            ->map(fn ($user) => (string) $user->getKey());
    }

    public function memberIds(string $team): Collection
    {
        $ids = $this->activeMemberships()->where('self_advocate_id', $team)->pluck('team_member_id');
        if (config('secure-access-acl.enabled')) {
            $directorIds = $this->nurseAssignments()->where('self_advocate_id', $team)->pluck('nurse_assigned_by');
            $directors = SecureAccess::newUserQuery()->whereKey($directorIds->unique()->all())->get()
                ->filter(fn ($user) => app(AccessControl::class)->isMember($user, 'administrative-director'));
            $ids = $ids->merge($directors->map(fn ($user) => (string) $user->getKey()));
        }

        return $ids->push($team)->map(fn ($id) => (string) $id)->unique()->values();
    }

    public function conversations(string $actor): Builder
    {
        return TeamConversation::query()
            ->whereIn('self_advocate_id', $this->teamIds($actor))
            ->whereHas('participants', fn (Builder $query) => $query->whereKey($actor));
    }

    public function authorize(string $actor, TeamConversation $conversation): void
    {
        abort_unless($this->conversations($actor)->whereKey($conversation->getKey())->exists(), 404);
    }

    public function unreadCount(string $actor): int
    {
        return TeamMessage::query()
            ->whereIn('conversation_id', $this->conversations($actor)->select('id'))
            ->where('sender_id', '!=', $actor)
            ->whereDoesntHave('reads', fn (Builder $query) => $query->where('user_id', $actor))
            ->count();
    }

    public function create(string $actor, string $team, array $participants): TeamConversation
    {
        abort_unless($this->teamIds($actor)->contains($team), 404);
        $participants = collect($participants)->map(fn ($id) => (string) $id)->unique();

        if (! $participants->contains($actor) || $participants->count() < 2
            || $participants->diff($this->memberIds($team))->isNotEmpty()) {
            throw ValidationException::withMessages(['participants' => __('Choose yourself and at least one current member of this team.')]);
        }

        return DB::transaction(function () use ($team, $participants) {
            $conversation = TeamConversation::create(['self_advocate_id' => $team]);
            $conversation->participants()->attach($participants->all());

            return $conversation;
        });
    }
}
