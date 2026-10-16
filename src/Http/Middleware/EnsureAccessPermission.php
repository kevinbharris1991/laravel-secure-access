<?php

namespace SecureAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SecureAccess\Authorization\AccessScope;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessPermission
{
    public function handle(Request $request, Closure $next, string $permission, ?string $parameter = null): Response
    {
        $subject = $parameter === null ? null : $request->route($parameter);

        abort_if($parameter !== null && ! $subject instanceof AccessScope, 403);

        Gate::forUser($request->user())->authorize('secure-access', [$permission, $subject]);

        return $next($request);
    }
}
