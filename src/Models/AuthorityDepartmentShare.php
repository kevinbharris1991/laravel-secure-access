<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityDepartmentShare extends Model
{
    public const DEPARTMENTS = ['medical-nursing', 'finance-accounting'];

    protected $table = 'secure_access_authority_department_shares';

    protected $fillable = [
        'document_id', 'department', 'created_by', 'summary', 'limitations', 'path', 'file_name',
    ];

    protected $hidden = ['summary', 'limitations', 'path'];

    protected $casts = [
        'summary' => 'encrypted',
        'limitations' => 'encrypted',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(TeamAuthorityDocument::class, 'document_id');
    }
}
