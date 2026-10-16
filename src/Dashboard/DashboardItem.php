<?php

namespace SecureAccess\Dashboard;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use SecureAccess\Authorization\AccessScope;

class DashboardItem
{
    public function __construct(
        public readonly string $permission,
        protected Closure $resolve,
        public readonly ?AccessScope $subject = null,
    ) {
    }

    public function visibleTo(?Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('secure-access', [$this->permission, $this->subject]);
    }

    public function resolveFor(?Authenticatable $user): mixed
    {
        Gate::forUser($user)->authorize('secure-access', [$this->permission, $this->subject]);

        return ($this->resolve)();
    }
}
