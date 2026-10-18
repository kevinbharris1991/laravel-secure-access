<?php

use Illuminate\Support\Facades\Route;
use SecureAccess\Http\Controllers\AuthorityReviewController;
use SecureAccess\Http\Controllers\AuthorityRevocationEvidenceController;

// Required inside the existing authenticated MFA route group.
Route::get('dashboard/authority', [AuthorityReviewController::class, 'index'])->name('authority.index');
Route::get('dashboard/authority/{document}', [AuthorityReviewController::class, 'show'])
    ->whereNumber('document')->name('authority.show');
Route::post('dashboard/authority/{document}', [AuthorityReviewController::class, 'update'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.update');
Route::post('dashboard/authority/{document}/revocation-evidence', [AuthorityRevocationEvidenceController::class, 'store'])
    ->whereNumber('document')->middleware('throttle:secure-access')->name('authority.evidence.store');
Route::get('dashboard/authority/evidence/{evidence}', [AuthorityRevocationEvidenceController::class, 'show'])
    ->whereNumber('evidence')->name('authority.evidence.show');
Route::get('dashboard/authority/evidence/{evidence}/download', [AuthorityRevocationEvidenceController::class, 'download'])
    ->whereNumber('evidence')->name('authority.evidence.download');
Route::post('dashboard/authority/evidence/{evidence}', [AuthorityRevocationEvidenceController::class, 'update'])
    ->whereNumber('evidence')->middleware('throttle:secure-access')->name('authority.evidence.update');
