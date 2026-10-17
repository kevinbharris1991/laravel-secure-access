# Laravel Secure Access

A Laravel package that hardens your login:

- **Log in with a username or any email address** – the primary email, the username, or any additional
  email address the user has registered and verified. If the user table has no configured email column,
  the primary email is stored as a verified address in `user_emails`.
- **Account lockout** – the account is locked after **3** consecutive failed attempts (wrong password *or*
  wrong multi-factor code).
- **Mandatory multi-factor authentication (MFA)** – users must register at least one method the first
  time they log in and must use one of their methods on **every** login. Supported methods:
  - 📱 **Text message (SMS)** one-time codes
  - 🔢 **Authenticator apps** (TOTP – Google Authenticator, Microsoft Authenticator, 1Password, Authy…)
  - 👆 **Biometrics** (WebAuthn platform authenticators – Touch ID, Face ID, Windows Hello, Android)
  - 🔑 **Hardware tokens** (WebAuthn / FIDO2 / U2F security keys such as YubiKeys)
- Users can register **multiple methods** and pick any of them at login.
- Views use the **native Laravel (Breeze) look & feel** and work without a front-end build step.

## Requirements

- PHP 8.2+ (PHP 8.3+ for Laravel 13)
- Laravel 11, 12 or 13
- The `openssl` extension (and `sodium` for Ed25519 security keys)

## Installation

```bash
composer require kevinbharris1991/laravel-secure-access
php artisan migrate
```

The migrations add `username`, `failed_login_attempts`, `locked_at`, `date_of_birth`, `gender`,
`registration_role`, `profile_photo_path`, `terms_accepted_at` and `privacy_policy_accepted_at` columns to `users` (the `username`
and registration profile columns are skipped if they already exist) and create the `user_emails`,
`mfa_methods`, `pending_registrations` and `pending_registration_roles` tables.

When upgrading an existing installation, run `php artisan migrate` before using
post-activation role selection; it requires the new `pending_registration_roles` table.

Optionally add the trait to your `User` model for convenient relationships:

```php
use SecureAccess\Concerns\HasSecureAccess;

class User extends Authenticatable
{
    use HasSecureAccess; // ->emails(), ->mfaMethods(), ->isLocked()
}
```

Publish the configuration (`config/secure-access.php` and `config/coppa.php`) and/or views if you want to
customise them:

```bash
php artisan vendor:publish --tag=secure-access-config
php artisan vendor:publish --tag=secure-access-views
```

## Usage

The package registers these routes (all names are prefixed with `secure-access.`):

| URL | Name | Purpose |
| --- | --- | --- |
| `GET /register` | `secure-access.register` | Account registration form |
| `POST /register` | `secure-access.register.store` | Validate the profile and email an activation link |
| `GET /register/sent` | `secure-access.register.sent` | "Check your email" page |
| `GET /register/activate/{registration}/{token}` | `secure-access.register.activate` | Activation page (signed link from the email) |
| `POST /register/activate/{registration}/{token}` | `secure-access.register.activate.store` | Create the account and begin role selection |
| `GET /register/role` | `secure-access.register.role` | Choose a registration role after activation (pending login required) |
| `POST /register/role` | `secure-access.register.role.store` | Save the registration role and continue to mandatory MFA enrollment |
| `GET /login` | `secure-access.login` | Username / email + password form |
| `POST /logout` | `secure-access.logout` | Log out |
| `GET /login/mfa` | `secure-access.challenge` | Choose an MFA method during login |
| `GET /mfa/setup` | `secure-access.setup` | Register an MFA method (forced on first login) |
| `GET /dashboard` | `secure-access.dashboard` | MFA-protected dashboard with tools based on current group permissions |
| `GET /dashboard/team` | `secure-access.team.index` | View team relationships and invitations |
| `POST /dashboard/team` | `secure-access.team.store` | Invite a team member by email |
| `POST /dashboard/team/invitations/{invitation}/accept` | `secure-access.team.invitations.accept` | Accept a team invitation |
| `DELETE /dashboard/team/invitations/{invitation}` | `secure-access.team.invitations.decline` | Decline a team invitation |
| `DELETE /dashboard/team/relationships/{relationship}` | `secure-access.team.relationships.destroy` | Remove a team relationship |
| `GET /security` | `secure-access.security` | Manage MFA methods and additional email addresses |
| `GET /access` | `secure-access.access.index` | Manage database groups, permissions and group grants |
| `GET /invitations` | `secure-access.invitations.index` | Create pending registrations with selected groups |
| `POST /invitations` | `secure-access.invitations.store` | Create an activation link for email or manual delivery |

The default post-login destination, `/dashboard`, is provided by the package.
Like the other package routes, it respects `secure-access.routes.prefix`; if you
set a prefix, update `secure-access.home` to the prefixed dashboard URL.

Protect your application's additional routes with the `secure-access.mfa` middleware. It redirects guests to the login
page, and any user that was authenticated without completing MFA in the current session (for example via a
"remember me" cookie or another login form) is logged out and must log in again with password + MFA:

```php
Route::middleware('secure-access.mfa')->group(function () {
    Route::get('/reports', ReportsController::class);
});
```

If you previously used Breeze/Jetstream/Fortify login or registration routes, remove them and point guests to the package's
login page, e.g. in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->redirectGuestsTo(fn () => route('secure-access.login'));
})
```

### Registration flow

1. **Profile.** `/register` asks for the user's first name, optional middle initial, last name, date of birth,
   email address and gender. The name parts are stored together as the user's full name. The user must agree
   to the Terms of Service and confirm they have read and understand the Privacy Policy
   (`registration.terms_url` / `registration.privacy_url`). Anyone younger than `minimum_age` in
   `config/coppa.php` (default `13`, env `COPPA_MINIMUM_AGE`) is rejected and nothing they entered is
   stored. The email address is trimmed, lowercased and must not already be used by any account (including
   verified additional addresses). Gender options come from `registration.genders`.
2. **Activation email.** The details are kept in `pending_registrations` (no user is created yet) and an
   activation link is emailed. The link is signed, expires five minutes after the pending registration is
   created, and can be used once; registering again with the same address replaces the details and
   invalidates earlier links (at most once every `registration.resend_after` seconds, default `60`). Expired rows can be removed with
   `php artisan model:prune --model="SecureAccess\Models\PendingRegistration"`.
3. **Activation page.** The link opens a page where the user may upload a profile photo (optional; JPG, PNG,
   GIF or WebP up to `registration.profile_photos.max_size` KB, stored on `registration.profile_photos.disk`
   in `profile_photo_path`) and must choose a username (when the username column exists) and a password,
   entered twice. Passwords need at least 12 characters, mixed-case letters, numbers and symbols, and
   Laravel's `Password::defaults()` rules are also applied. Usernames are trimmed, lowercased and checked
   case-insensitively against existing login identifiers. The account is then created with the primary
   email address marked verified. The `email_column`, `username_column`, and `user_model` settings are
   respected; if the configured email column is absent, the verified primary email is stored in
   `user_emails`. Optional profile columns (`name`, `date_of_birth`, `gender`, `profile_photo_path`, consent
   timestamps, `email_verified_at`) are only written when they exist. Custom tables must still provide
   defaults for any other required columns.
4. **About you.** Activation **does not authenticate the user or mark MFA verified**. It starts the same expiring
   pending session used after a successful password check and redirects to `secure-access.register.role`.
   “Which best describes you?” offers Self-advocate, Family member / Caregiver, Professional,
   and Other / None of the above. This required selection is saved in `registration_role` only when that
   optional column exists on the configured user table; it never assigns ACL groups or permissions.
   A durable `pending_registration_roles` record prevents skipping this step, including after signing in
   again if the pending session expires. Existing accounts are not forced through this step.
5. **MFA.** Role selection continues to `secure-access.setup` without authenticating the user. Only
   proving possession of an MFA method completes authentication, after which the user is sent to `home`
   (the dashboard). If enrollment expires, log in with the new credentials to resume setup.

With ACL enabled, the first account successfully activated on an empty website receives
`executive-director`. Later public registrations receive `guest`. Assignment happens in the
account-creation transaction, not when the profile form is submitted. A database bootstrap
record serializes activations and prevents reassigning executive access after all registered
accounts are deleted. Existing accounts prevent first-user elevation.

### Director-created registrations

After completing MFA, an `administrative-director` or `executive-director` can visit
`/invitations`, enter the new member's first name, optional middle initial, last name, date of
birth, gender and email address, and select one or more database groups. Profile validation
uses the same minimum age and gender settings as public registration. The name parts are
stored together as the full name in `pending_registrations`, alongside the other profile
details, selected groups, expiration and a SHA-256 hash of the activation token.
This requires the exact `invitations.create_all` permission; revoking its
database grant immediately removes the ability to create registrations. The director selects an
expiration of 5 minutes, 15 minutes, 1 day, 2 days, or 2 weeks, and chooses either to email the
activation link or to share it themselves. Manual delivery sends no email and displays the
signed link once on the invitation page after submission; share it securely before leaving
the page. Re-inviting the same address replaces the details and invalidates its previous link.
No user account is created until activation. The recipient accepts the terms and privacy
policy and chooses sign-in credentials without re-entering the director-provided profile.
Older invitations without a profile still collect those details during activation.
After activation, invitees complete the same role-selection step before MFA enrollment.
On activation, the selected groups are assigned instead of the normal Guest assignment. Publicly
submitted group IDs are ignored. No invitation code is needed.

### Team invitations

Self-advocates can invite a family member/caregiver or professional; family members/caregivers and
professionals can invite a self-advocate. The invitation form asks only for the invitee's email
address (and, for self-advocates, whether the invitee is a caregiver or professional). Registered
invitees receive an email and can accept or decline from their MFA-protected team page. Unregistered
invitees receive a link to register; their invitation is associated with their account after they
select the matching registration role, and the team relationship is then created.

Accepted relationships appear on both users' team pages: self-advocates see loved ones and staff,
professionals see clients, and caregivers see loved ones. Either participant can remove a
relationship. The relationship does not grant any additional access to private account data.

Routes use `routes.prefix` (empty by default), and registration submissions share the login per-IP
throttle. The package does not add homepage navigation or unprefixed `login` / `register` route names.
Link to the named routes explicitly, for example in your welcome view:

```blade
<a href="{{ route('secure-access.login') }}">Log in</a>
<a href="{{ route('secure-access.register') }}">Register</a>
```

### Login flow

1. The user enters a username or email address and their password.
2. If the password is wrong the failure counter is incremented; on the 3rd consecutive failure the account
   is locked and the `SecureAccess\Events\AccountLocked` event is dispatched.
3. If the password is correct the user is **not** logged in yet. If they have no MFA method they must
   register one (registering proves possession of the new factor and completes the login). Otherwise they
   choose one of their methods and verify it. Wrong codes / failed verifications also count towards the
   lockout.
4. After the second factor succeeds the user is logged in and the failure counter is reset.

### Unlocking accounts

Locked accounts stay locked until unlocked:

```bash
php artisan secure-access:unlock jane@example.com
```

Or set `SECURE_ACCESS_LOCKOUT_MINUTES` (`lockout.duration`) to unlock automatically after a number of minutes.
Listen for `AccountLocked` to notify the user or your support team.

### Additional email addresses

On the `/security` page users can add more email addresses. A signed verification link is mailed to the
new address; only **verified** addresses can be used to log in. An address can belong to only one account.

### SMS delivery

By default text messages are written to the log (`SecureAccess\Sms\LogSmsSender`) which is only suitable
for local development. Implement `SecureAccess\Contracts\SmsSender` with your provider (Twilio, Vonage, AWS
SNS, …) and set it in `config/secure-access.php`:

```php
'sms' => ['sender' => App\Sms\TwilioSmsSender::class, /* ... */],
```

```php
use SecureAccess\Contracts\SmsSender;

class TwilioSmsSender implements SmsSender
{
    public function send(string $phoneNumber, string $message): void
    {
        // $phoneNumber is E.164 formatted, e.g. +15555550123
    }
}
```

### Biometrics & hardware tokens (WebAuthn)

WebAuthn requires a secure context (HTTPS, or `http://localhost` during development). The relying party ID
and origin default to the host / origin of `APP_URL`; override them with `SECURE_ACCESS_WEBAUTHN_RP_ID` and
`SECURE_ACCESS_WEBAUTHN_ORIGIN` if needed.

- **Biometrics** request a platform authenticator and require user verification (fingerprint, face or
  device PIN) on registration and on every login.
- **Hardware security keys** request a roaming (cross-platform) authenticator and require user presence
  (a touch).

Supported algorithms: ES256, RS256 and EdDSA. Attestation is not requested (`none`), signature counters are
checked to detect cloned authenticators: authenticators that always report `0` are accepted, but once a credential
has reported a non-zero counter any assertion that does not increase it is rejected (the user then has to sign in
with another registered method and re-register the key).

## Configuration

See [`config/secure-access.php`](config/secure-access.php) for all options, including:

- `lockout.max_attempts` (default `3`) and `lockout.duration` (default `null` = until manually unlocked)
- `mfa.methods` – enable/disable individual method types
- `mfa.pending_login_timeout` – minutes allowed between the password step and the second factor
- `routes.prefix`, `routes.middleware`, `routes.throttle` – route registration and per-IP rate limiting
- `registration.*` – terms/privacy URLs, gender options, activation link lifetime and profile photo storage
- `coppa.minimum_age` (in `config/coppa.php`, default `13`) – minimum age required to register
- `home` – where users are redirected after logging in (default `/dashboard`)
- `username_column`, `email_column`, `user_model`, `guard`

## Database group-based authorization

ACL is enabled by default. Groups, permission scopes, grants and memberships are stored
in the database; there is no `config/secure-access-camp.php` runtime configuration.
Migrations initialize the twelve camp groups and their permissions once. Authentication
and mandatory MFA remain separate from authorization.

### Enable and assign permissions

Run `php artisan migrate` to initialize ACL. Existing installations that previously
disabled ACL must set `enabled` to `true` in `config/secure-access-acl.php`, clear/rebuild
their configuration cache, then migrate. The four namespaced ACL tables are loaded only when enabled:
groups, permissions, group grants, and polymorphic user memberships. User IDs may be
integer or string/UUID identifiers (up to 64 characters). No changes to the users
table are required.

Add `SecureAccess\Concerns\HasAccessGroups` to your Eloquent authenticatable model
alongside any existing traits for convenient membership relationships. Registration assignment
also works without this trait. Visit `/access` to create, edit or delete database groups and
permissions and update group grants. Group operations require `groups.manage_all`; permission
operations require `permissions.manage_all`; changing grants requires both. The Executive
Director initially has both permissions. The `executive-director` and `guest` keys are
reserved for registration; their display names and grants remain editable. Administrative
operations require MFA and CSRF-protected forms. You can also manage records in trusted code:

```php
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;

$group = AccessGroup::firstOrCreate(['key' => 'editors'], ['name' => 'Editors']);
$permission = Permission::firstOrCreate(
    ['key' => 'public_content.update_all'],
    ['scope' => 'all'],
);
$group->permissions()->syncWithoutDetaching([$permission->id]);
$user->accessGroups()->syncWithoutDetaching([$group->id]);

$user->hasAccessPermission('public_content.update_all'); // true
$user->accessGroups()->detach($group); // revoke membership
```

Permissions are the union of a user's current groups. Checks query current grants,
so loaded relationships do not retain revoked access. Missing permissions, missing
membership, disabled ACL, and unauthenticated users are denied. There is no implicit
superuser group, wildcard, deny grant, or permission inheritance. Protect group and
grant management endpoints; never accept membership or grant changes from public
registration input. Hard-deleting a model using the trait detaches its memberships;
soft deletion preserves them. Bulk query deletes bypass model events: detach
memberships explicitly when using those operations. Polymorphic memberships do not
have a foreign key to your application's user table.

### Scopes, gates, policies, and routes

Each permission has an exact, stable key and a scope:

| Scope | Required context |
|---|---|
| `all` | Membership alone grants this exact permission |
| `own` | A subject whose `isOwnedBy($user)` returns true |
| `assigned` | A subject whose `isAssignedTo($user)` returns true |

Unknown scopes fail closed. For custom permissions, set `scope` explicitly; key
suffixes are naming conventions, not a scope parser. Use `*_own`, `*_assigned`,
`*_all`, `*_aggregate`, and `*_only` consistently. The camp seeder infers `own` and
`assigned` from its keys; other preset keys use `all`.
Scope belongs to the unique permission key, not to an individual group grant:
use separate keys for different scopes. Guest `*_public` permissions authorize
only the public-data operation; the host must restrict its corresponding query.
`reports.read_aggregate` and `reports.read_all` are independent permissions.
`manage_all` does not implicitly grant separate `read_all` or `update_all` keys;
use the exact key that a route declares.

Use the namespaced Laravel Gate (also usable with native `@can` and `can` middleware):

```php
use Illuminate\Support\Facades\Gate;
use SecureAccess\Authorization\ScopeContext;

// Obtain ownership/assignment from trusted persisted data, not request fields.
$subject = new ScopeContext(
    ownerId: $booking->user_id,
    assignedUserIds: $booking->assignedUsers()->pluck('id')->all(),
);
Gate::authorize('secure-access', ['bookings.read_own', $subject]);
```

For an owned **create** action, build the context from the authenticated user's
identifier, and set the new record's owner server-side. Do not trust submitted owner
or assignee IDs. `ScopeContext` compares these IDs to `getAuthIdentifier()`. In
multi-tenant applications also enforce tenant boundaries in policies/query scopes.

Your bound Eloquent models can implement `SecureAccess\Authorization\AccessScope`
with `isOwnedBy(Authenticatable $user): bool` and
`isAssignedTo(Authenticatable $user): bool`. This enables route checks:

```php
Route::get('/bookings/{booking}', [BookingController::class, 'show'])
    ->middleware(['secure-access.mfa', 'secure-access.permission:bookings.read_own,booking']);

Route::get('/reports', [ReportController::class, 'index'])
    ->middleware(['secure-access.mfa', 'secure-access.permission:reports.read_aggregate']);
```

The optional second middleware argument is a **bound route parameter**, not request
input; it must implement `AccessScope`. Missing/unbound contexts return 403. Ensure
Laravel's `SubstituteBindings` runs before the permission middleware (as in the
standard `web` middleware group). Permission checks run before controller queries,
not before route model binding. Keep MFA middleware on protected routes: ACL never
marks a session MFA-verified. Middleware uses the request's current auth guard;
select the appropriate guard with your application's authentication middleware.

Alternatively, delegate from a resource policy to
`app(SecureAccess\Authorization\AccessControl::class)->allows($user, $key, $model)`.
Combine that with your business rules and tenant/query scopes. Laravel's normal
Gate `before` callbacks still apply to Gate-based checks; audit application-wide
superuser overrides. The direct `hasAccessPermission` method checks only package
grants, without application Gate overrides.

An aggregate/read-only key authorizes an operation, **not a data projection**.
Return only aggregate/redacted fields from its endpoint. Do not return full
clinical records under an aggregate, dietary, or safety-alert permission.
For collection endpoints, scope the query to permitted owners/assignments before
loading records; a permission check does not automatically filter SQL results.

### Permission-aware dashboard and navigation

The built-in `/dashboard` shows the user's current groups and account security
tools. Group management, permission management, and invitation cards appear only
when the user's combined database group grants authorize the corresponding
operations. Membership and grant changes take effect on the next request; group
names alone do not grant access. Users without applicable grants (or with ACL
disabled) still have a working dashboard with account security access.

Only existing package tools are linked; camp-specific workflows remain the host
application's responsibility. Customize `secure-access::dashboard.index` through
the published views, or set `secure-access.home` to your application's dashboard.

The `Dashboard`/`DashboardItem` classes can also resolve your application's widgets
or navigation entries without global registration:

```php
use SecureAccess\Dashboard\Dashboard;
use SecureAccess\Dashboard\DashboardItem;

$items = app(Dashboard::class)->resolveFor($request->user(), [
    'summary' => new DashboardItem(
        'reports.read_aggregate',
        fn () => ['count' => Camper::query()->count()],
    ),
    'my-booking' => new DashboardItem(
        'bookings.read_own',
        fn () => ['url' => route('bookings.show', $booking)],
        $subject,
    ),
]);
```

Unauthorized items are omitted, preserving keys, **without invoking their
closures**. Put protected queries, view rendering, and expensive work inside the
closure, not before item construction. `visibleTo($user)` checks a single item;
`resolveFor($user)` authorizes again before invoking it and throws on denial.
Use the same permission and scope on the destination route/API: hiding a link is
not backend authorization.

Blade supports both package and standard Gate conditionals:

```blade
@secureAccess('bookings.read_own', $subject)
    {{-- Only load/render protected content inside this block. --}}
@endsecureAccess

@can('secure-access', ['reports.read_aggregate'])
    <a href="/reports">Reports</a>
@endcan
```

`@elsesecureAccess(...)` and `@else` are supported by the package conditional.
Neither dashboard nor Blade checks undo data already loaded by a controller.

For group membership rather than permissions, use `@is` with the exact database
group **key**, not its display name:

```blade
@is('guest')
    {{-- Content for members of the guest group --}}
@elseis('executive-director')
    {{-- Content for members of the executive-director group --}}
@else
    {{-- Other users --}}
@endis
```

This checks current polymorphic memberships using the configured `secure-access.guard`
and works without the optional `HasAccessGroups` trait. Unauthenticated users,
unsupported or unsaved user models, and disabled ACL return false; disabled ACL
does not query ACL tables. Membership changes are reflected on subsequent renders.
Unlike Laravel's `@guest`, `@is('guest')` means membership in the `guest` group,
not an unauthenticated session. Membership does not imply a particular permission:
continue using `@secureAccess` or `@can` for permission checks. Hiding content does
not replace destination route authorization or mandatory MFA.

### Initial camp data: 12 groups

Migrations initialize the built-in definitions in the database. The compatibility command
can initialize an empty ACL database:

```bash
php artisan secure-access:seed-camp
```

Initialization is transactional and **never changes user memberships**. Existing database
customizations are authoritative; re-running the command does not restore revoked grants.
Manage groups and permissions in `/access`, not a configuration file.
It can also be invoked from a host application's seeder via
`app(SecureAccess\Authorization\CampPreset::class)->seed()`.

| # | Group | Representative grants / boundaries |
|---|---|---|
| 1 | Executive Director | System/audit read, executive users, aggregate camper/incident reports, financials, grant approval, public/board content; no raw clinical grant |
| 2 | Administrative Director (`administrative-director`) | Staff/groups/credentials, invitations, rosters/cabins/policies, administrative intake, compliance and exports |
| 3 | Medical & Nursing Staff | Medications/dosages/stock, health profiles/protocols, clinical incidents; no finance/content grants |
| 4 | Behavioral Support Services | Support plans, behavior logs, limited health context read-only, consultation notes |
| 5 | Accessibility & Adaptive Services | Accommodation read/update, adaptive equipment, facility audits, accessibility work orders |
| 6 | Program Directors | Activity schedules/adaptations, participation, safety alerts read-only; no clinical history |
| 7 | Counselors & DSPs | Assigned care plans/behavior strategies/allergy alerts, assigned daily logs and incident flags |
| 8 | Facilities & Cabin Maintenance | Work orders, inspections, emergency alerts; no camper records |
| 9 | Housekeeping | Sanitation/room requirements, own supply requests; no camper records |
| 10 | Catering & Dietary Support | Dietary read-only, menus/ingredients/allergen notices, special meals; no clinical history |
| 11 | Finance & Accounting | Billing/payments/refunds, payroll, grant funds/applications, financial reports; no clinical records |
| 12 | Guest | Own bookings/payments/accommodations/health/diet disclosures, public meals/activities/events, own activity commitments and grant applications |

Guest grant applications use `grant_applications.create_own`, `read_own`,
`update_own`, and `submit_own` (each prefixed `grant_applications.`); they cannot
read another guest's application or approve applications. Enforce workflow rules
such as locking submitted applications in your policies.

Automatic assignments apply to the package's verified activation flow, not arbitrary
user-model creation. For existing websites, provision administrative membership through
trusted code. To retain authentication without ACL, set `enabled` to `false` in
`config/secure-access-acl.php`; registrations then do not assign groups, and ACL management
and invitation endpoints deny access.
The preset grants are additive: assigning multiple groups combines their access,
so department boundaries depend on appropriate membership administration, not
explicit deny rules.

### Camp dashboard and workflows

The MFA-protected `/dashboard` includes authorized module links, staff account
management, and a guest meals/activities portal. Routes respect
`secure-access.routes.prefix`. Each module has a list, record view, validated
create/edit forms, and only its permitted workflow actions. Blade uses the existing
`secure-access` Gate with `@can`; controllers independently enforce exact action
permissions and ownership/assignment on direct requests.

Run migrations after upgrading. New installations seed granular permissions along
with the original compatibility vocabulary. Existing installations **do not receive
new grants automatically**. Review `SecureAccess\Camp\Modules::grants()` and assign
permissions through `/access`, or deliberately apply the module matrix:

```bash
php artisan migrate
php artisan secure-access:seed-camp --grant-modules
```

The explicit option can restore previously revoked **module** grants. Ordinary
`secure-access:seed-camp` remains non-restorative. Legacy `manage_all` permissions
do not imply granular delete access to new modules.

#### Module permission matrix

`C/R/U/D` mean create/read/update/delete; omitted actions are denied. The executable
field allowlists and full permission keys are in `src/Camp/Modules.php`. Keys follow
`module.action_scope` (for example `health_profiles.update_all`,
`care_plans.read_assigned`, `dietary_restrictions.read_only`,
`bookings.update_own`). `only`, `public`, and `aggregate` permission suffixes use the
ACL's `all` scope, with additional server-side projection/publication restrictions.

| Group | Modules and actions |
|---|---|
| Executive Director | System/audit read; executive accounts; camper/incident aggregates only; revenue/budgets/financial approvals CRU and approve; grant applications CRU/approve; public content/board documents CRUD |
| Administrative Director | Staff accounts CRUD/role assignment/credential resets; rosters/cabins/policies/onboarding and intake registrations/waivers/consents/households CRU; compliance audit, administrative report export, form templates CRUD |
| Medical & Nursing | Medications/stock/dosages CRUD; health profiles/protocols/clinical incidents CRU; publish bounded safety, allergy, care, and behavioral-health context |
| Behavioral Support | Support plans and consultations CRUD; behavior logs CRU; behavioral-health context R; counselor strategy publication |
| Accessibility & Adaptive | Accommodations RU; adaptive equipment CRUD; facility audits CRU; accessibility work orders CR |
| Program Directors | Activity schedules/events CRUD; adaptation logs CRU; participation RU; safety alerts R only |
| Counselors & DSPs | Assigned care plans, communication/behavior strategies and allergy alerts R; assigned daily logs CR; assigned incident flags C only |
| Facilities & Maintenance | Work orders R/update status/assign; inspections CRU; emergency alerts R/acknowledge; no camper records |
| Housekeeping | Sanitation rosters/room requirements RU; own supply requests CR; no camper records |
| Catering & Dietary | Dietary restrictions R only; menus/ingredients/allergen notices CRU; special meals RU; no clinical/behavioral notes |
| Finance & Accounting | Billing/payments/refunds/reimbursements/payroll/grant funds CRUD; time cards R/approve; grant applications RU; financial report export; no PHI |
| Guest | Own bookings CRU/cancel, balance payment request, accommodations/health/dietary disclosures CRU; published meal plans/activities/events R/commit; own special meals and activity commitments R |

The existing ACL remains additive: staff administrators must not combine otherwise
incompatible roles. Staff-account role assignment protects executive and
permission-administrator privileges from escalation. Executive-account management
is restricted to executive accounts.

#### Data boundaries and audit

Camp records use encrypted JSON with module-specific validated field allowlists,
not an unrestricted shared notes payload. Keep `APP_KEY` backed up and use encrypted
database backups and TLS in the host application. Ownership includes the user's
morph type as well as identifier; client-supplied ownership cannot transfer a record.
Counselor access requires a trusted camper assignment, never a supplied list of IDs.
Use intake registration IDs as camper identifiers when linking records.

Administrators assign counselors using a roster's **Assign** action with a counselor
account ID and an intake registration ID. Nursing staff can publish care plans,
allergy alerts, safety summaries, and bounded behavioral-health context linked to
that camper; behavioral staff publish the separate counselor strategy records.
Counselors cannot create assignments or alter those plans.

Clinical source records, behavior context, safety-only alerts, dietary restrictions,
and administrative intake are separate modules. Publish only the permitted context
into the narrow modules; never copy full clinical histories into a safety alert,
dietary restriction, work order, room setup, or financial description. Finance,
Facilities, and Housekeeping have no route grants for camper modules. Executive
reports return counts rather than raw camper data.

Sensitive views, exports, and mutations produce metadata-only audit records in
`secure_access_camp_audits` (actor, module, action, record ID, timestamp), without
copying medical notes, credentials, or financial payloads. ACL-setting views and
changes are audited as well. Audit records have no dashboard mutation route.
Configure retention, database access, monitoring, and protected backups in the host
application; this package is not a HIPAA/compliance certification.

#### Guest self-service

Guests manage their own reservations and disclosures using the same scoped modules.
The meals/activities page shows published meal plans and available public events.
A guest-specific incompatible meal replaces only the matching service date and
breakfast/lunch/dinner period: the standard meal is struck through and the approved
alternative is displayed. Other guests' special meals are never loaded.
Activity commitments use server-derived ownership and repeat enrollment is
idempotent. The guest page lists only that guest's commitments.

Program staff publish guest-facing **Activities**/**Events** using the publication
selector; drafts are denied even by direct URL. Catering publishes standard
**Meal plans** separately from internal menus. Guest dietary disclosures project
only names, food allergens, and dietary instructions into the catering dashboard
(not environmental/drug allergies or clinical notes). Published meals generate
guest-owned pending special-meal review rows for disclosed diets. Catering updates
those rows with the alternative and incompatibility decision; guests cannot create
or change substitutions. Finance creates guest-owned balance records using the
guest account ID; guests can request payment of only those balances.

Payment requests are workflow records, **not a payment gateway**. Integrate a trusted
payment processor and verified callbacks before accepting money; a guest request
must not be treated as proof that a balance is settled. Likewise payroll, clinical,
and financial records are operational forms/logs, not external pharmacy, banking,
or payroll-provider integrations.

#### Local test accounts

No default passwords or production accounts are installed. In a disposable
`local`/`testing` host application after migration:

```bash
php artisan secure-access:demo-users
```

The command prompts for a password (minimum 12 characters), creates one account per
group, and skips existing accounts without changing passwords or roles. Usernames
are `camp-<group-key>` and emails are `camp-<group-key>@example.test`:

| Group | Test username |
|---|---|
| Executive Director | `camp-executive-director` |
| Administrative Director | `camp-administrative-director` |
| Medical & Nursing | `camp-medical-nursing` |
| Behavioral Support | `camp-behavioral-support` |
| Accessibility & Adaptive | `camp-accessibility-adaptive` |
| Program Directors | `camp-program-directors` |
| Counselors & DSPs | `camp-counselors-dsps` |
| Facilities & Maintenance | `camp-facilities-maintenance` |
| Housekeeping | `camp-housekeeping` |
| Catering & Dietary | `camp-catering-dietary` |
| Finance & Accounting | `camp-finance-accounting` |
| Guest | `camp-guest` |

These accounts still require MFA enrollment.
The demo command requires the configured username/email columns plus `name` and
`password`; custom host user schemas may seed equivalent accounts themselves.

## Testing

```bash
composer install
composer test
```

## License

MIT
