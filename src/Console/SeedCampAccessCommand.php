<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use SecureAccess\Authorization\CampPreset;

class SeedCampAccessCommand extends Command
{
    protected $signature = 'secure-access:seed-camp';

    protected $description = 'Seed the optional camp access groups and representative permissions';

    public function handle(CampPreset $preset): int
    {
        if (! config('secure-access-acl.enabled')) {
            $this->error('Enable secure-access-acl and run migrations before seeding.');

            return self::FAILURE;
        }

        $preset->seed();
        $this->info('Camp access groups seeded. No user memberships were changed.');

        return self::SUCCESS;
    }
}
