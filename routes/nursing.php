<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\NurseTeamController;

Route::prefix('dashboard/nursing')->name('nursing.')->middleware('throttle:secure-access')->group(function () {
    Route::get('/', [NurseTeamController::class, 'index'])->name('index');
    Route::post('/', [NurseTeamController::class, 'store'])->name('store');
    Route::delete('{relationship}', [NurseTeamController::class, 'destroy'])
        ->whereNumber('relationship')->name('destroy');
});
