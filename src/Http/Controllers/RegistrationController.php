<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\SecureAccess;
use SecureAccess\Support\PendingLogin;
use SecureAccess\Support\UserResolver;

class RegistrationController extends Controller
{
    public function __construct(
        protected UserResolver $users,
        protected PendingLogin $pending,
    ) {
    }

    public function create(): View|RedirectResponse
    {
        $user = SecureAccess::guard()->user();

        if ($user && SecureAccess::mfaVerified($user)) {
            return redirect(config('secure-access.home', '/'));
        }

        return view('secure-access::auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $authenticated = SecureAccess::guard()->user();

        if ($authenticated && SecureAccess::mfaVerified($authenticated)) {
            return redirect(config('secure-access.home', '/'));
        }

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'email' => $this->emailRules(),
            'gender' => ['required', 'string', 'max:255'],
            'terms' => ['accepted'],
            'privacy_policy' => ['accepted'],
        ]);

        $registration = PendingRegistration::create(collect($validated)->only([
            'name', 'date_of_birth', 'email', 'gender',
        ])->all() + [
            'expires_at' => now()->addMinutes(ActivateAccount::EXPIRES),
        ]);

        Notification::route('mail', $registration->email)->notify(new ActivateAccount($registration));

        $this->clearAuthentication($request);

        return redirect()->route('secure-access.register.sent');
    }

    public function sent(): View
    {
        return view('secure-access::auth.registration-sent');
    }

    public function activate(Request $request, string $registration): View|RedirectResponse
    {
        $user = SecureAccess::guard()->user();

        if ($user && SecureAccess::mfaVerified($user)) {
            return redirect(config('secure-access.home', '/'));
        }

        $this->registration($registration);

        return view('secure-access::auth.activate', [
            'fields' => $this->fields(),
            'activationUrl' => $request->fullUrl(),
        ]);
    }

    public function complete(Request $request, string $registration): RedirectResponse
    {
        $authenticated = SecureAccess::guard()->user();

        if ($authenticated && SecureAccess::mfaVerified($authenticated)) {
            return redirect(config('secure-access.home', '/'));
        }

        $this->registration($registration);
        $fields = $this->fields();

        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }

        $rules = [
            'password' => [
                'required', 'string', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
                Password::defaults(),
            ],
        ];

        if (isset($fields['username'])) {
            $rules['username'] = ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($this->users->find($value)) {
                    $fail(__('This username is already in use.'));
                }
            }];
        }

        $validated = $request->validate($rules);
        $connection = SecureAccess::newUserQuery()->getModel()->getConnection();

        try {
            $user = $connection->transaction(function () use ($registration, $fields, $validated) {
                $registration = $this->registration($registration, true);
                validator(['email' => $registration->email], ['email' => $this->emailRules()])->validate();
                $model = SecureAccess::userModel();
                $user = new $model;
                $attributes = [];

                foreach ($fields as $field => $column) {
                    $attributes[$column] = match ($field) {
                        'username' => $validated['username'],
                        'date_of_birth' => $registration->date_of_birth->toDateString(),
                        default => $registration->getAttribute($field),
                    };
                }

                $attributes[$user->getAuthPasswordName()] = Hash::make($validated['password']);
                if ($user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'email_verified_at')) {
                    $attributes['email_verified_at'] = now();
                }

                $user->forceFill($attributes)->save();
                $registration->delete();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'username' => __('This username or email address is already in use.'),
            ]);
        }

        $this->clearAuthentication($request);
        $this->pending->start($user);

        return redirect()->route('secure-access.setup');
    }

    protected function clearAuthentication(Request $request): void
    {
        SecureAccess::guard()->logout();
        $this->pending->clear();
        $request->session()->forget([SecureAccess::MFA_VERIFIED_SESSION_KEY, 'secure_access.setup', 'secure_access.webauthn']);
        $request->session()->regenerate();
    }

    protected function registration(string $id, bool $lock = false): PendingRegistration
    {
        $query = PendingRegistration::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        $registration = $query->findOrFail($id);
        abort_if($registration->expires_at->isPast(), 403, __('This activation link has expired.'));

        return $registration;
    }

    protected function emailRules(): array
    {
        return ['bail', 'required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) {
            if ($this->users->emailInUse($value) || $this->users->find($value)) {
                $fail(__('This email address is already in use.'));
            }
        }];
    }

    protected function fields(): array
    {
        $model = SecureAccess::newUserQuery()->getModel();
        $schema = $model->getConnection()->getSchemaBuilder();
        $fields = [
            'email' => config('secure-access.email_column', 'email'),
            'date_of_birth' => 'date_of_birth',
            'gender' => 'gender',
        ];

        if ($schema->hasColumn($model->getTable(), 'name')) {
            $fields['name'] = 'name';
        }

        if ($username = $this->users->usernameColumn()) {
            $fields['username'] = $username;
        }

        return $fields;
    }
}
