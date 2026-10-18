<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMessageRead extends Model
{
    protected $table = 'secure_access_team_message_reads';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['message_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
