<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'secure_access_permissions';

    protected $fillable = ['key', 'scope'];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AccessGroup::class, 'secure_access_group_grants', 'permission_id', 'group_id');
    }
}
