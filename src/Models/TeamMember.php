<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamMember extends Model
{
    protected $table = 'secure_access_team_members';

    protected $fillable = [
        'self_advocate_id',
        'team_member_id',
        'team_member_role',
        'ended_at',
    ];

    protected $casts = ['ended_at' => 'datetime'];

    public function authorityDocuments(): HasMany
    {
        return $this->hasMany(TeamAuthorityDocument::class, 'team_member_id');
    }
}
