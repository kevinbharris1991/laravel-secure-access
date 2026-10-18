<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\FinancialApplicationPermissionController;

// Required inside the existing authenticated MFA route group.
Route::get('dashboard/authority-shares/items/{share}/financial-actions', [FinancialApplicationPermissionController::class, 'show'])
    ->whereNumber('share')->name('finance-actions.show');
Route::post('dashboard/authority-shares/items/{share}/financial-actions', [FinancialApplicationPermissionController::class, 'store'])
    ->whereNumber('share')->middleware('throttle:secure-access')->name('finance-actions.store');
