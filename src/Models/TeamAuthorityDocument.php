<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class TeamAuthorityDocument extends Model
{
    public const TYPES = ['guardianship', 'conservatorship', 'power_of_attorney'];

    protected $table = 'secure_access_team_authority_documents';

    protected $fillable = [
        'team_member_id',
        'type',
        'path',
        'file_name',
    ];
}
