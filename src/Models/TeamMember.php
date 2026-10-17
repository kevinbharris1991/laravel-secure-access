<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $table = 'secure_access_team_members';

    protected $fillable = [
        'self_advocate_id',
        'team_member_id',
        'team_member_role',
    ];
}
