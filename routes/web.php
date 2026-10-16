<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\ChallengeController;
use SecureAccess\Http\Controllers\LoginController;
use SecureAccess\Http\Controllers\RegistrationController;
use SecureAccess\Http\Controllers\SecurityController;
use SecureAccess\Http\Controllers\SetupController;
use SecureAccess\Models\MfaMethod;

Route::middleware(config('secure-access.routes.middleware', ['web']))
    ->prefix(config('secure-access.routes.prefix', ''))
    ->name('secure-access.')
    ->group(function () {
        Route::get('register', [RegistrationController::class, 'create'])->name('register');
        Route::post('register', [RegistrationController::class, 'store'])->middleware('throttle:secure-access')->name('register.store');
        Route::get('register/sent', [RegistrationController::class, 'sent'])->name('register.sent');
        Route::get('activate/{registration}', [RegistrationController::class, 'activate'])
            ->middleware(['signed', 'throttle:secure-access'])->name('activate');
        Route::post('activate/{registration}', [RegistrationController::class, 'complete'])
            ->middleware(['signed', 'throttle:secure-access'])->name('activate.store');

        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:secure-access')->name('login.store');
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        // Second factor on every login.
        Route::get('login/mfa', [ChallengeController::class, 'index'])->name('challenge');
        Route::get('login/mfa/{method}', [ChallengeController::class, 'show'])->name('challenge.show');
        Route::post('login/mfa/{method}/send', [ChallengeController::class, 'send'])->middleware('throttle:secure-access')->name('challenge.send');
        Route::post('login/mfa/{method}', [ChallengeController::class, 'verify'])->middleware('throttle:secure-access')->name('challenge.verify');

        // Method registration (forced on first login, optional afterwards).
        Route::get('mfa/setup', [SetupController::class, 'index'])->name('setup');
        Route::post('mfa/setup/sms/send', [SetupController::class, 'sendSms'])->middleware('throttle:secure-access')->name('setup.sms.send');
        Route::get('mfa/setup/{type}', [SetupController::class, 'create'])->whereIn('type', MfaMethod::TYPES)->name('setup.create');
        Route::post('mfa/setup/{type}', [SetupController::class, 'store'])->whereIn('type', MfaMethod::TYPES)->middleware('throttle:secure-access')->name('setup.store');

        Route::middleware('secure-access.mfa')->group(function () {
            Route::get('security', [SecurityController::class, 'index'])->name('security');
            Route::delete('security/mfa/{method}', [SecurityController::class, 'destroyMethod'])->name('methods.destroy');
            Route::post('security/emails', [SecurityController::class, 'storeEmail'])->middleware('throttle:secure-access')->name('emails.store');
            Route::post('security/emails/{email}/resend', [SecurityController::class, 'resendEmail'])->middleware('throttle:secure-access')->name('emails.resend');
            Route::delete('security/emails/{email}', [SecurityController::class, 'destroyEmail'])->name('emails.destroy');
        });

        Route::get('security/emails/{email}/verify/{hash}', [SecurityController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('emails.verify');
    });
