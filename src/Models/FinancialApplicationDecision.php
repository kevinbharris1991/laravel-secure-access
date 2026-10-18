<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialApplicationDecision extends Model
{
    public const TARGETS = ['financial_aid', 'tuition'];
    public const ACTIONS = ['revoke', 'reinstate'];

    public $timestamps = false;

    protected $table = 'secure_access_financial_application_decisions';

    protected $guarded = ['id'];

    protected $hidden = ['basis', 'reason', 'document_signature'];

    protected $casts = [
        'basis' => 'encrypted', 'reason' => 'encrypted',
        'reviewed_at' => 'datetime', 'effective_at' => 'date',
        'assignment_version' => 'integer', 'share_revision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Financial decisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Financial decisions are immutable.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(TeamAuthorityDocument::class, 'document_id');
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(AuthorityDepartmentShare::class, 'share_id');
    }
}
