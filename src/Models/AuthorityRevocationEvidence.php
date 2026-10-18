<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityRevocationEvidence extends Model
{
    public const STATUSES = ['pending', 'accepted', 'rejected', 'needs_information'];

    protected $table = 'secure_access_authority_revocation_evidence';

    protected $fillable = [
        'document_id', 'submitted_by', 'status', 'path', 'file_name', 'comments',
        'reviewed_by', 'reviewed_at', 'review_history',
    ];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['path', 'comments', 'review_history'];

    protected $casts = [
        'comments' => 'encrypted',
        'review_history' => 'encrypted:array',
        'reviewed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(TeamAuthorityDocument::class, 'document_id');
    }
}
