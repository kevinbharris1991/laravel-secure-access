<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\UserEmail;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\SecureAccess;
use SecureAccess\Support\PendingLogin;
use SecureAccess\Support\UserResolver;
use Throwable;

/**
 * The profile form (name parts, date of birth, email, gender and consent)
 * emails an activation link; the activation page collects the credentials and creates the account,
 * followed by role selection and multi-factor enrollment before the user is authenticated.
 */
class RegistrationController extends Controller
{
    public function __construct(
        protected UserResolver $users,
        protected PendingLogin $pending,
    ) {
    }

    public function create(): View|RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        return view('secure-access::auth.register', [
            'genders' => $this->genders(),
            'minimumAge' => $this->minimumAge(),
            'latestBirthDate' => $this->latestBirthDate()->toDateString(),
            'termsUrl' => config('secure-access.registration.terms_url'),
            'privacyUrl' => config('secure-access.registration.privacy_url'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:1'],
            'last_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($request) {
                $nameParts = [
                    $request->input('first_name'),
                    $request->input('middle_initial'),
                    $value,
                ];
                $name = implode(' ', array_filter($nameParts, fn ($part) => is_string($part) && $part !== ''));

                if (mb_strlen($name) > 255) {
                    $fail(__('The full name may not be greater than 255 characters.'));
                }
            }],
            'date_of_birth' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', function ($attribute, $value, $fail) {
                if (Carbon::createFromFormat('!Y-m-d', $value)->greaterThan($this->latestBirthDate())) {
                    $fail(__('You must be at least :age years old to register.', ['age' => $this->minimumAge()]));
                }
            }],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) {
                if ($this->users->emailInUse($value) || $this->users->find($value)) {
                    $fail(__('This email address is already in use.'));
                }
            }],
            'gender' => ['required', 'string', Rule::in(array_map('strval', array_keys($this->genders())))],
            'terms' => ['accepted'],
            'privacy' => ['accepted'],
        ], [
            'date_of_birth.date_format' => __('Please enter a valid date of birth.'),
            'date_of_birth.after_or_equal' => __('Please enter a valid date of birth.'),
            'terms.accepted' => __('You must agree to the Terms of Service.'),
            'privacy.accepted' => __('You must acknowledge that you have read and understand the Privacy Policy.'),
        ]);

        $existing = PendingRegistration::query()->where('email', $validated['email'])->first();
        $resendAfter = max(0, (int) config('secure-access.registration.resend_after', 60));

        // Avoid flooding an inbox (or repeatedly invalidating its link) through repeated submissions.
        if ($existing && $existing->name !== null && $existing->expires_at?->isFuture()
            && $existing->updated_at?->greaterThan(now()->subSeconds($resendAfter))) {
            return redirect()->route('secure-access.register.sent')->with('registration_email', $existing->email);
        }

        $token = Str::random(64);

        // Registering again with the same address replaces the details and invalidates earlier links.
        $registration = PendingRegistration::query()->updateOrCreate(['email' => $validated['email']], [
            'name' => implode(' ', array_filter([
                $validated['first_name'],
                $validated['middle_initial'] ?? null,
                $validated['last_name'],
            ], fn ($part) => $part !== null && $part !== '')),
            'date_of_birth' => $validated['date_of_birth'],
            'gender' => $validated['gender'],
            'registration_role' => null,
            'token' => PendingRegistration::hashToken($token),
            'terms_accepted_at' => now(),
            'privacy_policy_accepted_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'group_ids' => $existing?->group_ids,
        ]);

        Notification::route('mail', $registration->email)->notify(new ActivateAccount($registration, $token));

        return redirect()->route('secure-access.register.sent')->with('registration_email', $registration->email);
    }

    public function sent(Request $request): View
    {
        return view('secure-access::auth.activation-sent', [
            'email' => $request->session()->get('registration_email'),
        ]);
    }

    public function activate(Request $request, string $registration, string $token): View|RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        if (! $pending = $this->registration($request, $registration, $token)) {
            return $this->invalidLink();
        }

        return view('secure-access::auth.activate', [
            'registration' => $pending,
            'action' => $request->fullUrl(),
            'username' => $this->users->usernameColumn() !== null,
            'maxPhotoSize' => $this->maxPhotoSize(),
            'completeProfile' => $pending->name === null,
            'acceptTerms' => $pending->terms_accepted_at === null,
            'acceptPrivacy' => $pending->privacy_policy_accepted_at === null,
            'genders' => $this->genders(),
            'minimumAge' => $this->minimumAge(),
            'latestBirthDate' => $this->latestBirthDate()->toDateString(),
            'termsUrl' => config('secure-access.registration.terms_url'),
            'privacyUrl' => config('secure-access.registration.privacy_url'),
        ]);
    }

    public function complete(Request $request, string $registration, string $token): RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        if (! $pending = $this->registration($request, $registration, $token)) {
            return $this->invalidLink();
        }

        $usernameColumn = $this->users->usernameColumn();

        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }

        $rules = [
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$this->maxPhotoSize()],
            'password' => [
                'required', 'string', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
                Password::defaults(),
            ],
        ];

        if ($usernameColumn) {
            $rules['username'] = ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($this->users->find($value)) {
                    $fail(__('This username is already in use.'));
                }
            }];
        }

        if ($pending->name === null) {
            $rules += [
                'first_name' => ['required', 'string', 'max:255'],
                'middle_initial' => ['nullable', 'string', 'max:1'],
                'last_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($request) {
                    $nameParts = [$request->input('first_name'), $request->input('middle_initial'), $value];
                    $name = implode(' ', array_filter($nameParts, fn ($part) => is_string($part) && $part !== ''));

                    if (mb_strlen($name) > 255) {
                        $fail(__('The full name may not be greater than 255 characters.'));
                    }
                }],
                'date_of_birth' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', function ($attribute, $value, $fail) {
                    if (Carbon::createFromFormat('!Y-m-d', $value)->greaterThan($this->latestBirthDate())) {
                        $fail(__('You must be at least :age years old to register.', ['age' => $this->minimumAge()]));
                    }
                }],
                'gender' => ['required', 'string', Rule::in(array_map('strval', array_keys($this->genders())))],
            ];
        }

        if ($pending->terms_accepted_at === null) {
            $rules['terms'] = ['accepted'];
        }

        if ($pending->privacy_policy_accepted_at === null) {
            $rules['privacy'] = ['accepted'];
        }

        $validated = $request->validate($rules);

        if ($this->users->emailInUse($pending->email)) {
            $pending->delete();

            return redirect()->route('secure-access.register')
                ->withErrors(['email' => __('This email address is already in use.')]);
        }

        $photo = $request->file('profile_photo')?->store(
            config('secure-access.registration.profile_photos.directory', 'profile-photos'),
            ['disk' => $this->photoDisk()]
        );

        try {
            $user = DB::transaction(function () use ($pending, $validated, $usernameColumn, $photo, $token) {
                // Serialize account creation even when the user table is empty.
                $state = DB::table('secure_access_registration_state')->where('id', 1)->lockForUpdate()->first();
                $model = SecureAccess::userModel();
                $first = ! $state->claimed && ! (new $model)->newQueryWithoutScopes()->exists();

                $pending = PendingRegistration::query()->whereKey($pending->getKey())->lockForUpdate()->first();
                if (! $pending || ! $pending->expires_at->isFuture() || ! $pending->tokenMatches($token)) {
                    return null;
                }

                if ($pending->name === null) {
                    $pending->forceFill([
                        'name' => implode(' ', array_filter([
                            $validated['first_name'],
                            $validated['middle_initial'] ?? null,
                            $validated['last_name'],
                        ], fn ($part) => $part !== null && $part !== '')),
                        'date_of_birth' => $validated['date_of_birth'],
                        'gender' => $validated['gender'],
                    ]);
                }

                if ($pending->terms_accepted_at === null) {
                    $pending->terms_accepted_at = now();
                }

                if ($pending->privacy_policy_accepted_at === null) {
                    $pending->privacy_policy_accepted_at = now();
                }

                // Consuming the registration atomically makes the activation link single-use.
                if (PendingRegistration::query()->whereKey($pending->getKey())->delete() !== 1) {
                    return null;
                }

                $groups = [];
                if ($pending->group_ids !== null) {
                    if (! config('secure-access-acl.enabled')) {
                        throw ValidationException::withMessages(['activation' => __('The registration groups have changed. Please contact the person who invited you.')]);
                    }

                    $groups = AccessGroup::query()->whereIn('id', $pending->group_ids)->lockForUpdate()->pluck('id')->all();
                    if (count($groups) !== count($pending->group_ids) || $groups === []) {
                        throw ValidationException::withMessages(['activation' => __('The selected registration groups have changed. Please contact the person who created this registration.')]);
                    }
                }

                $user = $this->createUser($pending, $validated, $usernameColumn, $photo ?: null);
                DB::table('pending_registration_roles')->updateOrInsert(['user_id' => (string) $user->getKey()]);
                if (config('secure-access-acl.enabled')) {
                    if ($first || $groups === []) {
                        $default = AccessGroup::query()->where('key', $first ? 'executive-director' : 'guest')->firstOrFail();
                        $groups = [$default->id];
                    }
                    foreach ($groups as $groupId) {
                        DB::table('secure_access_memberships')->insert([
                            'group_id' => $groupId,
                            'member_type' => $user->getMorphClass(),
                            'member_id' => (string) $user->getKey(),
                        ]);
                    }
                }
                DB::table('secure_access_registration_state')->where('id', 1)->update(['claimed' => true]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $this->deletePhoto($photo);

            if (! $usernameColumn || $this->users->emailInUse($pending->email)) {
                throw ValidationException::withMessages(['email' => __('This email address is already in use.')]);
            }

            throw ValidationException::withMessages(['username' => __('This username is already in use.')]);
        } catch (Throwable $e) {
            $this->deletePhoto($photo);

            throw $e;
        }

        if (! $user) {
            $this->deletePhoto($photo);

            return $this->invalidLink();
        }

        SecureAccess::guard()->logout();
        $request->session()->forget([SecureAccess::MFA_VERIFIED_SESSION_KEY, 'url.intended']);
        $request->session()->regenerate();
        $this->pending->start($user);

        return redirect()->route('secure-access.register.role')
            ->with('status', __('Your account has been activated. Tell us which best describes you to continue.'));
    }

    public function role(): View|RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        if (! $user = $this->pending->user()) {
            return redirect()->route('secure-access.login');
        }

        if (! $this->pending->requiresRegistrationRole($user)) {
            return redirect()->route('secure-access.challenge');
        }

        return view('secure-access::auth.registration-role', [
            'registrationRoles' => $this->registrationRoles(),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        if ($this->authenticated()) {
            return redirect(config('secure-access.home', '/'));
        }

        if (! $user = $this->pending->user()) {
            return redirect()->route('secure-access.login');
        }

        if (! $this->pending->requiresRegistrationRole($user)) {
            return redirect()->route('secure-access.challenge');
        }

        $validated = $request->validate([
            'registration_role' => ['required', 'string', Rule::in(array_keys($this->registrationRoles()))],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $requirement = DB::table('pending_registration_roles')
                ->where('user_id', (string) $user->getKey())->lockForUpdate()->first();

            if (! $requirement) {
                return;
            }

            $columns = $user->getConnection()->getSchemaBuilder()->getColumnListing($user->getTable());
            if (in_array('registration_role', array_map('strtolower', $columns), true)) {
                $user->forceFill(['registration_role' => $validated['registration_role']])->save();
            }

            DB::table('pending_registration_roles')->where('user_id', (string) $user->getKey())->delete();
        });

        return redirect()->route('secure-access.setup');
    }

    protected function createUser(PendingRegistration $pending, array $validated, ?string $usernameColumn, ?string $photo): Model
    {
        $model = SecureAccess::userModel();
        $user = new $model;
        $emailColumn = $this->users->emailColumn();

        $attributes = [
            $user->getAuthPasswordName() => Hash::make($validated['password']),
        ];

        if ($emailColumn) {
            $attributes[$emailColumn] = $pending->email;
        }

        if ($usernameColumn) {
            $attributes[$usernameColumn] = $validated['username'];
        }

        // Optional profile columns are only written when they exist on the user table.
        $optional = [
            'name' => $pending->name,
            'date_of_birth' => $pending->date_of_birth?->toDateString(),
            'gender' => $pending->gender,
            'profile_photo_path' => $photo,
            'terms_accepted_at' => $pending->terms_accepted_at,
            'privacy_policy_accepted_at' => $pending->privacy_policy_accepted_at,
            // Following the emailed activation link proves ownership of the address.
            'email_verified_at' => now(),
        ];
        $schema = $user->getConnection()->getSchemaBuilder();
        $columns = array_map('strtolower', $schema->getColumnListing($user->getTable()));

        foreach ($optional as $column => $value) {
            if (in_array($column, $columns, true)) {
                $attributes[$column] = $value;
            }
        }

        $user->forceFill($attributes)->save();

        if (! $emailColumn) {
            UserEmail::create([
                'user_id' => $user->getKey(),
                'email' => $pending->email,
                'verified_at' => now(),
            ]);
        }

        return $user;
    }

    protected function registration(Request $request, string $id, string $token): ?PendingRegistration
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $registration = PendingRegistration::query()->find($id);

        return $registration && $registration->expires_at?->isFuture() && $registration->tokenMatches($token)
            ? $registration
            : null;
    }

    protected function invalidLink(): RedirectResponse
    {
        return redirect()->route('secure-access.register')->withErrors([
            'activation' => __('This activation link is invalid or has expired. Please register again.'),
        ]);
    }

    protected function authenticated(): bool
    {
        $user = SecureAccess::guard()->user();

        return $user && SecureAccess::mfaVerified($user);
    }

    protected function genders(): array
    {
        return (array) config('secure-access.registration.genders', []);
    }

    protected function registrationRoles(): array
    {
        return [
            'self_advocate' => __('Self-advocate: I am an individual with high support needs.'),
            'family_caregiver' => __('Family member / Caregiver: I support a relative with high support needs.'),
            'professional' => __('Professional: I work in a field or career supporting individuals with high support needs.'),
            'employee' => __("Employee: I work for Camp Cody's."),
            'other' => __('Other / None of the above'),
        ];
    }

    protected function minimumAge(): int
    {
        return max(0, (int) config('coppa.minimum_age', 13));
    }

    /**
     * The latest date of birth that satisfies the minimum age today.
     */
    protected function latestBirthDate(): Carbon
    {
        return Carbon::today()->subYearsNoOverflow($this->minimumAge());
    }

    protected function maxPhotoSize(): int
    {
        return max(1, (int) config('secure-access.registration.profile_photos.max_size', 2048));
    }

    protected function photoDisk(): string
    {
        return config('secure-access.registration.profile_photos.disk') ?: 'public';
    }

    protected function deletePhoto(string|false|null $photo): void
    {
        if ($photo) {
            Storage::disk($this->photoDisk())->delete($photo);
        }
    }
}
