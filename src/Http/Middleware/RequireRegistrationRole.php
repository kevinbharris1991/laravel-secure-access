<?php

namespace SecureAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SecureAccess\Support\PendingLogin;

class RequireRegistrationRole
{
    public function __construct(protected PendingLogin $pending)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $this->pending->user();

        if ($user && $this->pending->requiresRegistrationRole($user)) {
            return redirect()->route('secure-access.register.role');
        }

        return $next($request);
    }
}
