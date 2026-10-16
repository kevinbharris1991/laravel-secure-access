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
`profile_photo_path`, `terms_accepted_at` and `privacy_policy_accepted_at` columns to `users` (the `username`
and registration profile columns are skipped if they already exist) and create the `user_emails`,
`mfa_methods` and `pending_registrations` tables.

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
| `POST /register/activate/{registration}/{token}` | `secure-access.register.activate.store` | Create the account and begin mandatory MFA enrollment |
| `GET /login` | `secure-access.login` | Username / email + password form |
| `POST /logout` | `secure-access.logout` | Log out |
| `GET /login/mfa` | `secure-access.challenge` | Choose an MFA method during login |
| `GET /mfa/setup` | `secure-access.setup` | Register an MFA method (forced on first login) |
| `GET /security` | `secure-access.security` | Manage MFA methods and additional email addresses |

Protect your application's routes with the `secure-access.mfa` middleware. It redirects guests to the login
page, and any user that was authenticated without completing MFA in the current session (for example via a
"remember me" cookie or another login form) is logged out and must log in again with password + MFA:

```php
Route::middleware('secure-access.mfa')->group(function () {
    Route::get('/dashboard', DashboardController::class);
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

1. **Profile.** `/register` asks for the user's name, date of birth, email address and gender. The user must
   agree to the Terms of Service and confirm they have read and understand the Privacy Policy
   (`registration.terms_url` / `registration.privacy_url`). Anyone younger than `minimum_age` in
   `config/coppa.php` (default `13`, env `COPPA_MINIMUM_AGE`) is rejected and nothing they entered is
   stored. The email address is trimmed, lowercased and must not already be used by any account (including
   verified additional addresses). Gender options come from `registration.genders`.
2. **Activation email.** The details are kept in `pending_registrations` (no user is created yet) and an
   activation link is emailed. The link is signed, expires after `registration.activation_expires` minutes
   (default `60`) and can be used once; registering again with the same address replaces the details and
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
4. **MFA.** Activation **does not authenticate the user or mark MFA verified**. It starts the same expiring
   pending session used after a successful password check and redirects to `secure-access.setup`. Only
   proving possession of an MFA method completes authentication, after which the user is sent to `home`
   (the dashboard). If enrollment expires, log in with the new credentials to resume setup.

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

## Testing

```bash
composer install
composer test
```

## License

MIT
