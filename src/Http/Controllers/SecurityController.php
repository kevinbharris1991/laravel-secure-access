<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Models\UserEmail;
use SecureAccess\Notifications\VerifyAdditionalEmail;
use SecureAccess\SecureAccess;
use SecureAccess\Support\UserResolver;

/**
 * Account security page: multi-factor methods and additional email addresses.
 */
class SecurityController extends Controller
{
    public function __construct(protected UserResolver $users)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user(config('secure-access.guard'));

        return view('secure-access::security.index', [
            'user' => $user,
            'primaryEmail' => $user->getAttribute(config('secure-access.email_column', 'email')),
            'username' => $user->getAttribute(config('secure-access.username_column', 'username')),
            'methods' => MfaMethod::query()->where('user_id', $user->getKey())->orderBy('id')->get(),
            'emails' => UserEmail::query()->where('user_id', $user->getKey())->orderBy('id')->get(),
            'availableTypes' => SecureAccess::enabledMethods(),
        ]);
    }

    public function destroyMethod(Request $request, MfaMethod $method): RedirectResponse
    {
        $user = $request->user(config('secure-access.guard'));
        abort_unless((string) $method->user_id === (string) $user->getKey(), 404);

        $request->validateWithBag('removeMethod', [
            'password' => ['required', 'current_password:'.config('secure-access.guard')],
        ]);

        $removed = DB::transaction(function () use ($user, $method) {
            $count = MfaMethod::query()->where('user_id', $user->getKey())->lockForUpdate()->count();

            return $count > 1 && $method->delete();
        });

        if (! $removed) {
            throw ValidationException::withMessages([
                'password' => __('You must keep at least one multi-factor authentication method.'),
            ])->errorBag('removeMethod');
        }

        return redirect()->route('secure-access.security')->with('status', __(':name removed.', ['name' => $method->name]));
    }

    public function storeEmail(Request $request): RedirectResponse
    {
        $user = $request->user(config('secure-access.guard'));

        $validated = $request->validateWithBag('addEmail', [
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($this->users->emailInUse($validated['email'])) {
            throw ValidationException::withMessages([
                'email' => __('This email address is already in use.'),
            ])->errorBag('addEmail');
        }

        // Unverified claims on the address (by any account) are superseded.
        UserEmail::query()->where('email', mb_strtolower(trim($validated['email'])))->whereNull('verified_at')->delete();

        $email = UserEmail::create(['user_id' => $user->getKey(), 'email' => $validated['email']]);

        $this->sendVerification($email);

        return redirect()->route('secure-access.security')
            ->with('status', __('We sent a verification link to :email.', ['email' => $email->email]));
    }

    public function resendEmail(Request $request, UserEmail $email): RedirectResponse
    {
        $this->authorizeEmail($request, $email);

        if (! $email->isVerified()) {
            $this->sendVerification($email);
        }

        return redirect()->route('secure-access.security')
            ->with('status', __('We sent a verification link to :email.', ['email' => $email->email]));
    }

    public function destroyEmail(Request $request, UserEmail $email): RedirectResponse
    {
        $this->authorizeEmail($request, $email);

        $email->delete();

        return redirect()->route('secure-access.security')->with('status', __(':email removed.', ['email' => $email->email]));
    }

    public function verifyEmail(UserEmail $email, string $hash): RedirectResponse
    {
        abort_unless(hash_equals($email->verificationHash(), $hash), 403);

        if (! $email->isVerified()) {
            if ($this->users->emailInUse($email->email)) {
                $email->delete();

                return redirect()->route('secure-access.security')
                    ->withErrors(['email' => __('This email address is already in use.')], 'addEmail');
            }

            $email->forceFill(['verified_at' => now()])->save();
        }

        return redirect()->route('secure-access.security')
            ->with('status', __(':email has been verified. You can now use it to sign in.', ['email' => $email->email]));
    }

    protected function authorizeEmail(Request $request, UserEmail $email): void
    {
        abort_unless((string) $email->user_id === (string) $request->user(config('secure-access.guard'))->getKey(), 404);
    }

    protected function sendVerification(UserEmail $email): void
    {
        Notification::route('mail', $email->email)->notify(new VerifyAdditionalEmail($email));
    }
}
