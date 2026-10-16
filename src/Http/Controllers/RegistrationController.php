<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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

        return view('secure-access::auth.register', ['fields' => $this->fields()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $authenticated = SecureAccess::guard()->user();

        if ($authenticated && SecureAccess::mfaVerified($authenticated)) {
            return redirect(config('secure-access.home', '/'));
        }

        $fields = $this->fields();

        foreach (['email', 'username'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => mb_strtolower(trim($request->input($field)))]);
            }
        }

        $rules = [
            'email' => ['bail', 'required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) {
                if ($this->users->emailInUse($value) || $this->users->find($value)) {
                    $fail(__('This email address is already in use.'));
                }
            }],
            'password' => [
                'required', 'string', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
                Password::defaults(),
            ],
        ];

        if (isset($fields['name'])) {
            $rules['name'] = ['required', 'string', 'max:255'];
        }

        if (isset($fields['username'])) {
            $rules['username'] = ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($this->users->find($value)) {
                    $fail(__('This username is already in use.'));
                }
            }];
        }

        $validated = $request->validate($rules);
        $model = SecureAccess::userModel();
        $user = new $model;
        $attributes = [];

        foreach ($fields as $field => $column) {
            $attributes[$column] = $validated[$field];
        }

        $attributes[$user->getAuthPasswordName()] = Hash::make($validated['password']);
        $user->forceFill($attributes)->save();

        SecureAccess::guard()->logout();
        $request->session()->forget(SecureAccess::MFA_VERIFIED_SESSION_KEY);
        $request->session()->regenerate();
        $this->pending->start($user);

        return redirect()->route('secure-access.setup');
    }

    protected function fields(): array
    {
        $model = SecureAccess::newUserQuery()->getModel();
        $schema = $model->getConnection()->getSchemaBuilder();
        $fields = ['email' => config('secure-access.email_column', 'email')];

        if ($schema->hasColumn($model->getTable(), 'name')) {
            $fields['name'] = 'name';
        }

        if ($username = $this->users->usernameColumn()) {
            $fields['username'] = $username;
        }

        return $fields;
    }
}
