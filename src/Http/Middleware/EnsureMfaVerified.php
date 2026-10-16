<?php

namespace SecureAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SecureAccess\SecureAccess;
use SecureAccess\Support\PendingLogin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user completed multi-factor authentication in this session.
 * Users authenticated any other way (e.g. a "remember me" cookie or another login form)
 * are logged out and sent through the multi-factor challenge.
 */
class EnsureMfaVerified
{
    public function __construct(protected PendingLogin $pending)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = SecureAccess::guard();
        $user = $guard->user();

        if (! $user) {
            return $request->expectsJson()
                ? abort(401)
                : redirect()->guest(route('secure-access.login'));
        }

        if (SecureAccess::mfaVerified($user)) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            abort(403, __('Multi-factor authentication is required.'));
        }

        $request->session()->put('url.intended', $request->fullUrl());
        $this->pending->start($user);

        return redirect()->route('secure-access.challenge');
    }
}
