<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessGroup extends Model
{
    protected $table = 'secure_access_groups';

    protected $fillable = ['key', 'name'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'secure_access_group_grants', 'group_id', 'permission_id');
    }
}
