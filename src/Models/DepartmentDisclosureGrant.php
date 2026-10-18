<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentDisclosureGrant extends Model
{
    protected $table = 'secure_access_department_disclosure_grants';

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'assignment_version' => 'integer',
        'share_revision' => 'integer',
        'notified_at' => 'datetime',
    ];
}
