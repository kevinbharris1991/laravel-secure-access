<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamAuthorityDocument extends Model
{
    public const TYPES = ['guardianship', 'conservatorship', 'power_of_attorney'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'needs_information', 'revoked', 'superseded', 'expired'];

    protected $table = 'secure_access_team_authority_documents';

    protected $fillable = [
        'team_member_id',
        'type',
        'path',
        'file_name',
        'status',
        'submitted_by',
        'approved_scopes',
        'effective_at',
        'expires_at',
        'previous_document_id',
        'reviewed_by',
        'reviewed_at',
        'review_history',
    ];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['path', 'review_history'];

    protected $casts = [
        'approved_scopes' => 'array',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'review_history' => 'encrypted:array',
    ];

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AuthorityDocumentAudit::class, 'document_id');
    }
}
