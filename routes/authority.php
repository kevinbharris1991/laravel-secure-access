<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\AuthorityReviewController;
use SecureAccess\Http\Controllers\AuthorityRevocationEvidenceController;
use SecureAccess\Http\Controllers\AuthorityDepartmentShareController;

// Required inside the existing authenticated MFA route group.
Route::get('dashboard/authority', [AuthorityReviewController::class, 'index'])->name('authority.index');
Route::get('dashboard/authority/{document}', [AuthorityReviewController::class, 'show'])
    ->whereNumber('document')->name('authority.show');
Route::post('dashboard/authority/{document}', [AuthorityReviewController::class, 'update'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.update');
Route::post('dashboard/authority/{document}/lifecycle', [AuthorityReviewController::class, 'lifecycle'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.lifecycle');
Route::post('dashboard/authority/{document}/shares', [AuthorityDepartmentShareController::class, 'store'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.shares.store');
Route::get('dashboard/authority-shares/{department}', [AuthorityDepartmentShareController::class, 'index'])
    ->whereIn('department', ['medical-nursing', 'finance-accounting'])->name('authority.shares.department');
Route::get('dashboard/authority-shares/items/{share}', [AuthorityDepartmentShareController::class, 'show'])
    ->whereNumber('share')->name('authority.shares.show');
Route::put('dashboard/authority-shares/items/{share}', [AuthorityDepartmentShareController::class, 'update'])
    ->whereNumber('share')->middleware('throttle:secure-access')->name('authority.shares.update');
Route::delete('dashboard/authority-shares/items/{share}', [AuthorityDepartmentShareController::class, 'destroy'])
    ->whereNumber('share')->middleware('throttle:secure-access')->name('authority.shares.destroy');
Route::get('dashboard/authority-shares/items/{share}/download', [AuthorityDepartmentShareController::class, 'download'])
    ->whereNumber('share')->name('authority.shares.download');
Route::post('dashboard/authority/{document}/revocation-evidence', [AuthorityRevocationEvidenceController::class, 'store'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.evidence.store');
Route::get('dashboard/authority/evidence/{evidence}', [AuthorityRevocationEvidenceController::class, 'show'])
    ->whereNumber('evidence')->name('authority.evidence.show');
Route::get('dashboard/authority/evidence/{evidence}/download', [AuthorityRevocationEvidenceController::class, 'download'])
    ->whereNumber('evidence')->name('authority.evidence.download');
Route::post('dashboard/authority/evidence/{evidence}', [AuthorityRevocationEvidenceController::class, 'update'])
    ->whereNumber('evidence')->middleware('throttle:secure-access')->name('authority.evidence.update');
