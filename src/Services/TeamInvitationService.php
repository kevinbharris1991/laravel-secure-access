<?php

namespace SecureAccess\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\TeamInvitation;
use SecureAccess\Notifications\TeamInvitationNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Support\UserResolver;

class TeamInvitationService
{
    public const ROLES = ['self_advocate', 'family_caregiver', 'professional'];

    public function invite(Model $inviter, string $email, string $inviteeRole): TeamInvitation
    {
        $email = mb_strtolower(trim($email));
        $inviterRole = $inviter->getAttribute('registration_role');
        if (! in_array($inviterRole, self::ROLES, true)) {
            throw new \InvalidArgumentException('This account cannot invite team members.');
        }

        if ($inviterRole !== 'self_advocate') {
            $inviteeRole = 'self_advocate';
        }

        if (! in_array($inviteeRole, $this->allowedInviteeRoles($inviterRole), true)) {
            throw new \InvalidArgumentException('Select a valid team member role.');
        }

        $target = app(UserResolver::class)->find($email);
        $targetRole = $target?->getAttribute('registration_role');

        if ($target && $target->getKey() == $inviter->getKey()) {
            throw new \InvalidArgumentException('You cannot invite yourself.');
        }

        if ($targetRole !== null && $targetRole !== $inviteeRole) {
            throw new \InvalidArgumentException('This email address belongs to an account with a different account type.');
        }

        $invitation = TeamInvitation::query()
            ->where('inviter_id', (string) $inviter->getKey())
            ->where('invitee_email', $email)
            ->where('invitee_role', $inviteeRole)
            ->where('status', TeamInvitation::STATUS_PENDING)
            ->first();

        if (! $invitation) {
            $invitation = TeamInvitation::query()->create([
                'inviter_id' => (string) $inviter->getKey(),
                'invitee_email' => $email,
                'invitee_id' => $target?->getKey() === null ? null : (string) $target->getKey(),
                'inviter_role' => $inviterRole,
                'invitee_role' => $inviteeRole,
                'status' => TeamInvitation::STATUS_PENDING,
            ]);
        } elseif ($target) {
            $invitation->forceFill(['invitee_id' => (string) $target->getKey()])->save();
        }

        Notification::route('mail', $email)->notify(
            new TeamInvitationNotification($invitation, $target === null)
        );

        return $invitation;
    }

    public function allowedInviteeRoles(string $role): array
    {
        return match ($role) {
            'self_advocate' => ['family_caregiver', 'professional'],
            'family_caregiver', 'professional' => ['self_advocate'],
            default => [],
        };
    }

    public function resolvePendingInvitations(Model $user, string $role): void
    {
        $emailColumn = app(UserResolver::class)->emailColumn();
        $email = $emailColumn ? mb_strtolower(trim((string) $user->getAttribute($emailColumn))) : '';

        if ($email === '') {
            return;
        }

        $pending = TeamInvitation::query()
            ->where('status', TeamInvitation::STATUS_PENDING)
            ->where(function ($query) use ($email, $user) {
                $query->whereRaw('lower(invitee_email) = ?', [$email])
                    ->orWhere('invitee_id', (string) $user->getKey());
            })
            ->get();

        foreach ($pending as $invitation) {
            if ($invitation->invitee_role !== $role
                || ! in_array($invitation->inviter_role, $this->allowedInviteeRoles($role), true)) {
                $invitation->forceFill(['status' => TeamInvitation::STATUS_DECLINED])->save();

                continue;
            }

            $invitation->forceFill(['invitee_id' => (string) $user->getKey()])->save();
            Notification::route('mail', $email)->notify(
                new TeamInvitationNotification($invitation)
            );
        }
    }

    public function user(string $id): ?Model
    {
        return SecureAccess::newUserQuery()->find($id);
    }
}
