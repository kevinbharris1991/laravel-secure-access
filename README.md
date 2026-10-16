# Laravel Secure Access

A Laravel package that hardens your login:

- **Log in with a username or any email address** – the primary `users.email`, the username, or any
  additional email address the user has registered and verified.
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

- PHP 8.2+
- Laravel 11 or 12
- The `openssl` extension (and `sodium` for Ed25519 security keys)

## Installation

```bash
composer require kevinbharris1991/laravel-secure-access
php artisan migrate
```

The migrations add `username`, `failed_login_attempts` and `locked_at` columns to `users` (the `username`
column is skipped if it already exists) and create the `user_emails` and `mfa_methods` tables.

Optionally add the trait to your `User` model for convenient relationships:

```php
use SecureAccess\Concerns\HasSecureAccess;

class User extends Authenticatable
{
    use HasSecureAccess; // ->emails(), ->mfaMethods(), ->isLocked()
}
```

Publish the configuration and/or views if you want to customise them:

```bash
php artisan vendor:publish --tag=secure-access-config
php artisan vendor:publish --tag=secure-access-views
```

## Usage

The package registers these routes (all names are prefixed with `secure-access.`):

| URL | Name | Purpose |
| --- | --- | --- |
| `GET /login` | `secure-access.login` | Username / email + password form |
| `POST /logout` | `secure-access.logout` | Log out |
| `GET /login/mfa` | `secure-access.challenge` | Choose an MFA method during login |
| `GET /mfa/setup` | `secure-access.setup` | Register an MFA method (forced on first login) |
| `GET /security` | `secure-access.security` | Manage MFA methods and additional email addresses |

Protect your application's routes with the `secure-access.mfa` middleware. It redirects guests to the login
page, and any user that was authenticated without completing MFA in the current session (for example via a
"remember me" cookie or another login form) is logged out and sent through the MFA challenge:

```php
Route::middleware('secure-access.mfa')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

If you previously used Breeze/Jetstream/Fortify login routes, remove them and point guests to the package's
login page, e.g. in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->redirectGuestsTo(fn () => route('secure-access.login'));
})
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
checked to detect cloned authenticators.

## Configuration

See [`config/secure-access.php`](config/secure-access.php) for all options, including:

- `lockout.max_attempts` (default `3`) and `lockout.duration` (default `null` = until manually unlocked)
- `mfa.methods` – enable/disable individual method types
- `mfa.pending_login_timeout` – minutes allowed between the password step and the second factor
- `routes.prefix`, `routes.middleware`, `routes.throttle` – route registration and per-IP rate limiting
- `home` – where users are redirected after logging in (default `/dashboard`)
- `username_column`, `email_column`, `user_model`, `guard`

## Testing

```bash
composer install
composer test
```

## License

MIT
