<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\AuthorityReviewController;

// Required inside the existing authenticated MFA route group.
Route::get('dashboard/authority', [AuthorityReviewController::class, 'index'])->name('authority.index');
Route::get('dashboard/authority/{document}', [AuthorityReviewController::class, 'show'])
    ->whereNumber('document')->name('authority.show');
Route::post('dashboard/authority/{document}', [AuthorityReviewController::class, 'update'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.update');
