<?php

namespace SecureAccess\Console;

use Illuminate\Console\Command;
use SecureAccess\Support\AccountLockout;
use SecureAccess\Support\UserResolver;

class UnlockAccountCommand extends Command
{
    protected $signature = 'secure-access:unlock {identifier : Username or email address of the account}';

    protected $description = 'Unlock an account that was locked after too many failed login attempts';

    public function handle(UserResolver $users, AccountLockout $lockout): int
    {
        $user = $users->find((string) $this->argument('identifier'));

        if (! $user) {
            $this->components->error('No account matches that identifier.');

            return self::FAILURE;
        }

        $lockout->unlock($user);

        $this->components->info('The account has been unlocked.');

        return self::SUCCESS;
    }
}
