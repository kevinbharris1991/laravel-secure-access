<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use SecureAccess\Authorization\CampPreset;
use Illuminate\Support\Facades\DB;
use SecureAccess\Camp\Modules;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;

class SeedCampAccessCommand extends Command
{
    protected $signature = 'secure-access:seed-camp {--grant-modules : Explicitly grant the current module matrix to existing camp groups}';

    protected $description = 'Initialize an empty ACL database with camp groups and permissions.';

    public function handle(CampPreset $preset): int
    {
        if (! config('secure-access-acl.enabled')) {
            $this->error('Enable secure-access-acl (via SECURE_ACCESS_ACL_ENABLED env or config) and run migrations before seeding.');

            return self::FAILURE;
        }

        try {
            $preset->seed();
            if ($this->option('grant-modules')) {
                DB::transaction(function () {
                    foreach (Modules::grants() as $role => $keys) {
                        $group = AccessGroup::where('key', $role)->first();
                        if (! $group) {
                            continue;
                        }
                        foreach ($keys as $key) {
                            $scope = str_ends_with($key, '_own') ? 'own' : (str_ends_with($key, '_assigned') ? 'assigned' : 'all');
                            $permission = Permission::firstOrCreate(['key' => $key], ['scope' => $scope]);
                            $group->permissions()->syncWithoutDetaching([$permission->id]);
                        }
                    }
                });
                $this->warn('Module grants explicitly applied. Previously revoked module grants may have been restored.');
            }
            $this->info('Camp access groups and permissions seeded.');
            $this->info('Without --grant-modules, the database is authoritative and removed grants are not restored.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Seeding failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
