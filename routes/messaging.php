<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\MessagingController;

Route::prefix('messages')->name('messaging.')->group(function () {
    Route::get('/', [MessagingController::class, 'index'])->name('index');
    Route::get('create', [MessagingController::class, 'create'])->name('create');
    Route::post('/', [MessagingController::class, 'store'])->name('store');
    Route::get('{conversation}', [MessagingController::class, 'show'])->whereNumber('conversation')->name('show');
    Route::post('{conversation}/messages', [MessagingController::class, 'send'])->whereNumber('conversation')->name('send');
    Route::post('{conversation}/read', [MessagingController::class, 'read'])->whereNumber('conversation')->name('read');
});
