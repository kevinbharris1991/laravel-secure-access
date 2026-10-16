<?php

namespace SecureAccess\Camp;

use Illuminate\Database\Eloquent\Model;
use SecureAccess\Models\CampAudit;
use SecureAccess\SecureAccess;

class Audit
{
    public static function record(string $action, string $module, int|string|null $recordId = null, array $metadata = []): CampAudit
    {
        // Supplemental payloads are deliberately excluded from the metadata-only audit table.
        $actor = SecureAccess::guard()->user();
        abort_unless($actor instanceof Model && $actor->exists, 403);
        $audit = new CampAudit;
        $audit->actor_type = $actor->getMorphClass();
        $audit->actor_id = (string) $actor->getKey();
        $audit->action = $action;
        $audit->module = $module;
        $audit->record_id = $recordId !== null && ctype_digit((string) $recordId) ? $recordId : null;
        $audit->save();

        return $audit;
    }
}
