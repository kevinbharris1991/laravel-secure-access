<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentAssignment extends Model
{
    protected $table = 'secure_access_department_assignments';

    protected $fillable = ['ward_id', 'department', 'assignee_id', 'assigned_by', 'version'];

    protected $casts = ['version' => 'integer'];
}
