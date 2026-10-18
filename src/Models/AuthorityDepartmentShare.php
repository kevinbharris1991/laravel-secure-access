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
        'status', 'effective_at', 'expires_at', 'published_at', 'redacted_verified_at', 'revision',
    ];

    protected $hidden = ['summary', 'limitations', 'path'];

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected $casts = [
        'summary' => 'encrypted',
        'limitations' => 'encrypted',
        'effective_at' => 'date',
        'expires_at' => 'date',
        'published_at' => 'datetime',
        'redacted_verified_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(TeamAuthorityDocument::class, 'document_id');
    }
}
