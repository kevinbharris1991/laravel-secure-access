<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model & Guard
    |--------------------------------------------------------------------------
    |
    | The Eloquent model representing your users (defaults to the model of the
    | "users" auth provider) and the authentication guard used to log them in
    | once every authentication factor has been verified.
    |
    */

    'user_model' => env('SECURE_ACCESS_USER_MODEL'),

    'guard' => env('SECURE_ACCESS_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Identifier Columns
    |--------------------------------------------------------------------------
    |
    | Users may sign in with their username, their primary email address or
    | any additional (verified) email address registered on their account.
    |
    */

    'username_column' => 'username',

    'email_column' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    |
    | After "max_attempts" consecutive failed login attempts (wrong password or
    | wrong multi-factor code) the account is locked. When "duration" is null
    | the account stays locked until it is unlocked with the artisan command
    | `php artisan secure-access:unlock {identifier}`. Otherwise it unlocks
    | automatically after the given number of minutes.
    |
    */

    'lockout' => [
        'max_attempts' => (int) env('SECURE_ACCESS_MAX_ATTEMPTS', 3),
        'duration' => env('SECURE_ACCESS_LOCKOUT_MINUTES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Every user must register at least one method and must use one of them
    | on every login. Disable any method type you do not want to offer.
    |
    */

    'mfa' => [
        'methods' => ['sms', 'totp', 'biometric', 'security_key'],

        // Minutes a user has to complete the second factor after entering the password.
        'pending_login_timeout' => 10,

        'sms' => [
            // Any class implementing SecureAccess\Contracts\SmsSender.
            'sender' => SecureAccess\Sms\LogSmsSender::class,
            'code_length' => 6,
            'expires' => 10, // minutes
            'resend_after' => 30, // seconds
        ],

        'totp' => [
            'issuer' => env('APP_NAME', 'Laravel'),
            'digits' => 6,
            'period' => 30,
            'window' => 1,
        ],

        'webauthn' => [
            // Defaults to the host / origin of APP_URL.
            'rp_id' => env('SECURE_ACCESS_WEBAUTHN_RP_ID'),
            'rp_name' => env('APP_NAME', 'Laravel'),
            'origin' => env('SECURE_ACCESS_WEBAUTHN_ORIGIN'),
            'timeout' => 60000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Registration starts with the user's first name, optional middle initial,
    | last name, date of birth, email address and gender plus acceptance of the
    | terms of service and privacy policy. The minimum age is configured in
    | config/coppa.php ("minimum_age"). An
    | activation link is then emailed; on the activation page the user picks a
    | username and password and may upload a profile photo.
    |
    */

    'registration' => [
        'terms_url' => env('SECURE_ACCESS_TERMS_URL', '/terms'),
        'privacy_url' => env('SECURE_ACCESS_PRIVACY_URL', '/privacy'),

        // Stored value => label shown on the registration form.
        'genders' => [
            'female' => 'Female',
            'male' => 'Male',
            'non_binary' => 'Non-binary',
            'other' => 'Other',
            'prefer_not_to_say' => 'Prefer not to say',
        ],

        // Seconds before registering again with the same email address sends a new activation link.
        'resend_after' => 60,

        'profile_photos' => [
            'disk' => env('SECURE_ACCESS_PROFILE_PHOTO_DISK', 'public'),
            'directory' => 'profile-photos',
            'max_size' => 2048, // kilobytes
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes & Views
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'middleware' => ['web'],
        // Login attempts allowed per minute per IP address (all accounts).
        'throttle' => 10,
    ],

    // Where to send users after a fully authenticated login.
    'home' => '/dashboard',

];
