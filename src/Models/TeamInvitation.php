<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $table = 'secure_access_team_invitations';

    protected $fillable = [
        'inviter_id',
        'invitee_email',
        'invitee_id',
        'inviter_role',
        'invitee_role',
        'status',
    ];
}
