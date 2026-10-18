<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Services\AuthorityDepartmentShareService;

class SynchronizeAuthorityDisclosuresCommand extends Command
{
    protected $signature = 'secure-access:sync-authority-disclosures';

    protected $description = 'Re-evaluate scheduled and expired departmental disclosures and delivery grants';

    public function handle(AuthorityDepartmentShareService $shares): int
    {
        TeamAuthorityDocument::query()->orderBy('id')->chunkById(100, function ($documents) use ($shares) {
            foreach ($documents as $document) {
                $shares->synchronizeForDocument($document);
            }
        });

        return self::SUCCESS;
    }
}
