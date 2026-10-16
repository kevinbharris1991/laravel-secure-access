<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\ChallengeController;
use SecureAccess\Http\Controllers\DashboardController;
use SecureAccess\Http\Controllers\CampController;
use SecureAccess\Http\Controllers\GuestPortalController;
use SecureAccess\Http\Controllers\StaffController;
use SecureAccess\Http\Controllers\LoginController;
use SecureAccess\Http\Controllers\RegistrationController;
use SecureAccess\Http\Controllers\SecurityController;
use SecureAccess\Http\Controllers\SetupController;
use SecureAccess\Http\Controllers\InvitationController;
use SecureAccess\Http\Controllers\AccessManagementController;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Http\Middleware\AuditAccessChanges;

Route::middleware(config('secure-access.routes.middleware', ['web']))
    ->prefix(config('secure-access.routes.prefix', ''))
    ->name('secure-access.')
    ->group(function () {
        Route::get('register', [RegistrationController::class, 'create'])->name('register');
        Route::post('register', [RegistrationController::class, 'store'])->middleware('throttle:secure-access')->name('register.store');
        Route::get('register/sent', [RegistrationController::class, 'sent'])->name('register.sent');
        Route::get('register/activate/{registration}/{token}', [RegistrationController::class, 'activate'])
            ->whereNumber('registration')
            ->middleware('throttle:secure-access')
            ->name('register.activate');
        Route::post('register/activate/{registration}/{token}', [RegistrationController::class, 'complete'])
            ->whereNumber('registration')
            ->middleware('throttle:secure-access')
            ->name('register.activate.store');

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
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('dashboard/staff', [StaffController::class, 'index'])->name('staff.index');
            Route::post('dashboard/staff', [StaffController::class, 'store'])->middleware('throttle:secure-access')->name('staff.store');
            Route::put('dashboard/staff/{user}', [StaffController::class, 'update'])->middleware('throttle:secure-access')->name('staff.update');
            Route::delete('dashboard/staff/{user}', [StaffController::class, 'destroy'])->middleware('throttle:secure-access')->name('staff.destroy');
            Route::get('dashboard/guest', [GuestPortalController::class, 'index'])->name('guest.index');
            Route::post('dashboard/guest/{module}/{record}/commit', [GuestPortalController::class, 'commit'])
                ->whereIn('module', ['activities', 'events'])->whereNumber('record')
                ->middleware('throttle:secure-access')->name('guest.commit');
            Route::prefix('dashboard/modules/{module}')->name('camp.')->group(function () {
                Route::get('/', [CampController::class, 'index'])->name('index');
                Route::get('create', [CampController::class, 'create'])->name('create');
                Route::get('export', [CampController::class, 'export'])->name('export');
                Route::post('/', [CampController::class, 'store'])->middleware('throttle:secure-access')->name('store');
                Route::get('{record}', [CampController::class, 'show'])->whereNumber('record')->name('show');
                Route::get('{record}/edit', [CampController::class, 'edit'])->whereNumber('record')->name('edit');
                Route::put('{record}', [CampController::class, 'update'])->whereNumber('record')->middleware('throttle:secure-access')->name('update');
                Route::delete('{record}', [CampController::class, 'destroy'])->whereNumber('record')->middleware('throttle:secure-access')->name('destroy');
                Route::post('{record}/actions/{action}', [CampController::class, 'action'])
                    ->whereNumber('record')->middleware('throttle:secure-access')->name('action');
            });
            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:secure-access')->name('invitations.store');
            Route::get('access', [AccessManagementController::class, 'index'])->middleware(AuditAccessChanges::class)->name('access.index');
            Route::middleware(['throttle:secure-access', AuditAccessChanges::class])->prefix('access')->name('access.')->group(function () {
                Route::post('groups', [AccessManagementController::class, 'storeGroup'])->name('groups.store');
                Route::put('groups/{group}', [AccessManagementController::class, 'updateGroup'])->name('groups.update');
                Route::delete('groups/{group}', [AccessManagementController::class, 'destroyGroup'])->name('groups.destroy');
                Route::put('groups/{group}/permissions', [AccessManagementController::class, 'syncGroupPermissions'])->name('groups.permissions');
                Route::post('permissions', [AccessManagementController::class, 'storePermission'])->name('permissions.store');
                Route::put('permissions/{permission}', [AccessManagementController::class, 'updatePermission'])->name('permissions.update');
                Route::delete('permissions/{permission}', [AccessManagementController::class, 'destroyPermission'])->name('permissions.destroy');
            });
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
