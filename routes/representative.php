<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\RepresentativeController;

Route::prefix('dashboard/representative')->name('representative.')->group(function () {
    Route::get('/', [RepresentativeController::class, 'workspace'])->name('index');
    Route::get('{relationship}/{module}', [RepresentativeController::class, 'module'])
        ->whereNumber('relationship')->name('module');
    Route::post('{relationship}/{module}', [RepresentativeController::class, 'storeDelegated'])
        ->whereNumber('relationship')->middleware('throttle:secure-access')->name('store');
    Route::put('{relationship}/{module}/{record}', [RepresentativeController::class, 'updateDelegated'])
        ->whereNumber('relationship')->whereNumber('record')->middleware('throttle:secure-access')->name('update');
    Route::post('{relationship}/{module}/{record}/{action}', [RepresentativeController::class, 'actionDelegated'])
        ->whereNumber('relationship')->whereNumber('record')->middleware('throttle:secure-access')->name('action');
});
