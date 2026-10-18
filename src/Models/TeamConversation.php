<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SecureAccess\SecureAccess;

class TeamConversation extends Model
{
    protected $table = 'secure_access_team_conversations';

    protected $fillable = ['self_advocate_id'];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(SecureAccess::userModel(), 'secure_access_conversation_participants', 'conversation_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class, 'conversation_id');
    }
}
