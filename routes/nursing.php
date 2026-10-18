<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\NurseTeamController;
use SecureAccess\Http\Controllers\FinanceTeamController;

Route::prefix('dashboard/nursing')->name('nursing.')->middleware('throttle:secure-access')->group(function () {
    Route::get('/', [NurseTeamController::class, 'index'])->name('index');
    Route::post('/', [NurseTeamController::class, 'store'])->name('store');
    Route::delete('{relationship}', [NurseTeamController::class, 'destroy'])
        ->whereNumber('relationship')->name('destroy');
});

Route::prefix('dashboard/finance')->name('finance.')->middleware('throttle:secure-access')->group(function () {
    Route::get('/', [FinanceTeamController::class, 'index'])->name('index');
    Route::post('/', [FinanceTeamController::class, 'store'])->name('store');
    Route::delete('{assignment}', [FinanceTeamController::class, 'destroy'])
        ->whereNumber('assignment')->name('destroy');
});
