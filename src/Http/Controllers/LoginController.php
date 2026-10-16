<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\View\View;
use SecureAccess\SecureAccess;
use SecureAccess\Support\AccountLockout;
use SecureAccess\Support\PendingLogin;
use SecureAccess\Support\UserResolver;

class LoginController extends Controller
{
    public function __construct(
        protected UserResolver $users,
        protected AccountLockout $lockout,
        protected PendingLogin $pending,
    ) {
    }

    public function create(): View|RedirectResponse
    {
        $user = SecureAccess::guard()->user();

        if ($user && SecureAccess::mfaVerified($user)) {
            return redirect(config('secure-access.home', '/'));
        }

        return view('secure-access::auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->users->find($credentials['identifier']);

        if (! $user) {
            // Spend comparable time to avoid revealing which identifiers exist.
            Hash::make($credentials['password']);

            return $this->failed(__('These credentials do not match our records.'));
        }

        if ($this->lockout->isLocked($user)) {
            return $this->failed(__('This account has been locked after too many failed login attempts. Please contact support.'));
        }

        if (! Hash::check($credentials['password'], $user->getAuthPassword())) {
            if ($this->lockout->recordFailure($user)) {
                return $this->failed(__('This account has been locked after too many failed login attempts. Please contact support.'));
            }

            return $this->failed(__('These credentials do not match our records.'));
        }

        $request->session()->regenerate();
        $this->pending->start($user);

        return redirect()->route('secure-access.challenge');
    }

    public function destroy(Request $request): RedirectResponse
    {
        SecureAccess::guard()->logout();
        $this->pending->clear();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    protected function failed(string $message): RedirectResponse
    {
        return redirect()->route('secure-access.login')
            ->withErrors(['identifier' => $message])
            ->onlyInput('identifier');
    }
}
