<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamAuthorityDocument extends Model
{
    public const TYPES = ['guardianship', 'conservatorship', 'power_of_attorney'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'needs_information', 'inactive', 'suspended', 'revoked', 'superseded', 'expired'];
    public const REVIEW_DECISIONS = ['pending', 'confirmed', 'denied', 'inquired'];
    public const LIFECYCLE_STATUSES = ['active', 'inactive', 'superseded', 'suspended', 'revoked', 'expired'];

    protected $table = 'secure_access_team_authority_documents';

    protected $fillable = [
        'team_member_id',
        'ward_id',
        'revision',
        'type',
        'path',
        'file_name',
        'status',
        'review_decision',
        'lifecycle_status',
        'submitted_by',
        'approved_scopes',
        'effective_at',
        'expires_at',
        'previous_document_id',
        'reviewed_by',
        'reviewed_at',
        'review_history',
        'termination_basis',
    ];

    protected $attributes = ['status' => 'pending', 'review_decision' => 'pending', 'lifecycle_status' => 'active'];

    protected $hidden = ['path', 'review_history'];

    protected $casts = [
        'revision' => 'integer',
        'approved_scopes' => 'array',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'review_history' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty([
                'team_member_id', 'ward_id', 'type', 'revision', 'path', 'file_name',
                'submitted_by', 'previous_document_id',
            ])) {
                throw new \LogicException('Original authority document metadata is immutable.');
            }
        });
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AuthorityDocumentAudit::class, 'document_id');
    }

    public function revocationEvidence(): HasMany
    {
        return $this->hasMany(AuthorityRevocationEvidence::class, 'document_id');
    }

    public function statusLabel(): string
    {
        if ($this->status === 'revoked') {
            return __('Verified termination recorded');
        }
        if ($this->status === 'suspended') {
            return __('Website representative access suspended');
        }

        return __(ucwords(str_replace('_', ' ', $this->status)));
    }

    public function compatibilityStatus(): string
    {
        if ($this->lifecycle_status !== 'active') {
            return $this->lifecycle_status;
        }

        return match ($this->review_decision) {
            'confirmed' => 'approved',
            'denied' => 'rejected',
            'inquired' => 'needs_information',
            default => 'pending',
        };
    }
}
