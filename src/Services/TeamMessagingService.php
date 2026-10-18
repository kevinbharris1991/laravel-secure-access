<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\TeamConversation;
use SecureAccess\Models\TeamMember;
use SecureAccess\Models\TeamMessage;
use SecureAccess\SecureAccess;

class TeamMessagingService
{
    public function teamIds(string $actor): Collection
    {
        $ids = TeamMember::query()->where('team_member_id', $actor)->whereNull('ended_at')
            ->pluck('self_advocate_id');

        if (SecureAccess::newUserQuery()->whereKey($actor)->where('registration_role', 'self_advocate')->exists()) {
            $ids->push($actor);
        }

        return $ids->unique()->values();
    }

    public function memberIds(string $team): Collection
    {
        return TeamMember::query()->where('self_advocate_id', $team)->whereNull('ended_at')
            ->pluck('team_member_id')->push($team)->map(fn ($id) => (string) $id)->unique()->values();
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
