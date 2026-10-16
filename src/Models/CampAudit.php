<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class CampAudit extends Model
{
    protected $table = 'secure_access_camp_audits';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];
}
