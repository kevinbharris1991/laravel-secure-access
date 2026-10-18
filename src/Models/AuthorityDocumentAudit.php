<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class AuthorityDocumentAudit extends Model
{
    protected $table = 'secure_access_authority_document_audits';

    protected $fillable = ['document_id', 'team_member_id', 'actor_id', 'action', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
