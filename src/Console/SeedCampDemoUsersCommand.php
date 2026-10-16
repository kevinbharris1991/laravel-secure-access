<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use SecureAccess\Authorization\CampDefaults;
use SecureAccess\Models\AccessGroup;
use SecureAccess\SecureAccess;

class SeedCampDemoUsersCommand extends Command
{
    protected $signature = 'secure-access:demo-users';

    protected $description = 'Create local/test camp accounts using a prompted password; never overwrites accounts.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('secure-access-acl.enabled')) {
            $this->error('Demo accounts require the local/testing environment and enabled ACL.');

            return self::FAILURE;
        }

        $password = $this->secret('Password for the demo accounts (at least 12 characters)');
        if (Validator::make(['password' => $password], ['password' => 'required|string|min:12'])->fails()) {
            $this->error('A password of at least 12 characters is required.');

            return self::FAILURE;
        }

        $model = SecureAccess::newUserQuery()->getModel();
        $schema = $model->getConnection()->getSchemaBuilder();
        $usernameColumn = config('secure-access.username_column', 'username');
        $emailColumn = config('secure-access.email_column', 'email');
        if (! $usernameColumn || ! $emailColumn || ! $schema->hasColumns($model->getTable(), [$usernameColumn, $emailColumn, 'name', 'password'])) {
            $this->error('Demo seeding requires name, password, and configured username/email columns.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($password, $usernameColumn, $emailColumn) {
            foreach (CampDefaults::groups() as $role => $definition) {
                $group = AccessGroup::where('key', $role)->firstOrFail();
                $username = 'camp-'.$role;
                $email = $username.'@example.test';
                if (SecureAccess::newUserQuery()->where($usernameColumn, $username)->orWhere($emailColumn, $email)->exists()) {
                    $this->warn('Skipped existing account: '.$username);
                    continue;
                }
                $user = SecureAccess::newUserQuery()->getModel()->newInstance();
                $user->forceFill([
                    'name' => $definition['name'], $usernameColumn => $username,
                    $emailColumn => $email, 'password' => Hash::make($password),
                ])->save();
                DB::table('secure_access_memberships')->insert([
                    'group_id' => $group->id, 'member_type' => $user->getMorphClass(),
                    'member_id' => (string) $user->getKey(),
                ]);
                $this->info('Created '.$username);
            }
        });
        $this->info('MFA enrollment is still required at first login.');

        return self::SUCCESS;
    }
}
