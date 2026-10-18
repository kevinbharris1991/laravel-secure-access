<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SecureAccess\SecureAccess;

class TeamMessage extends Model
{
    protected $table = 'secure_access_team_messages';

    protected $fillable = ['conversation_id', 'sender_id', 'body'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(SecureAccess::userModel(), 'sender_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(TeamMessageRead::class, 'message_id');
    }
}
