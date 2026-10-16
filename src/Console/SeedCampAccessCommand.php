<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use SecureAccess\Authorization\CampPreset;

class SeedCampAccessCommand extends Command
{
    protected $signature = 'secure-access:seed-camp';

    protected $description = 'Seed or refresh camp access groups and permissions from configuration. Database is authoritative; existing grants are not restored.';

    public function handle(CampPreset $preset): int
    {
        if (! config('secure-access-acl.enabled')) {
            $this->error('Enable secure-access-acl (via SECURE_ACCESS_ACL_ENABLED env or config) and run migrations before seeding.');

            return self::FAILURE;
        }

        try {
            $preset->seed();
            $this->info('Camp access groups and permissions seeded.');
            $this->info('Note: Database is authoritative. Manually-removed grants will not be restored.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Seeding failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
