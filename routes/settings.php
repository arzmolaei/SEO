<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SystemBackupController;
use App\Http\Controllers\Settings\SystemRestoreController;
use App\Http\Controllers\Settings\SystemUpdateController;
use App\Http\Middleware\EnsureNoConflictingLifecycleRecovery;
use App\Http\Middleware\EnsureSystemOwner;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::get('settings/backup', [SystemBackupController::class, 'edit'])
        ->middleware(EnsureSystemOwner::class)
        ->name('settings.backup.edit');

    Route::get('settings/backup/download', [SystemBackupController::class, 'download'])
        ->middleware([RequirePassword::class, EnsureSystemOwner::class])
        ->name('settings.backup.download');

    Route::post('settings/backup/restore/verify', [SystemRestoreController::class, 'verify'])
        ->middleware([RequirePassword::class, EnsureSystemOwner::class, 'throttle:backup-restore'])
        ->name('settings.backup.restore.verify');

    Route::post('settings/backup/restore/confirm', [SystemRestoreController::class, 'confirm'])
        ->middleware([RequirePassword::class, EnsureSystemOwner::class, 'throttle:backup-restore'])
        ->name('settings.backup.restore.confirm');

    Route::post('settings/backup/restore/recover', [SystemRestoreController::class, 'recover'])
        ->middleware([RequirePassword::class, EnsureSystemOwner::class, 'throttle:backup-restore'])
        ->name('settings.backup.restore.recover');

    Route::get('settings/update', [SystemUpdateController::class, 'show'])
        ->middleware(EnsureSystemOwner::class)
        ->name('settings.update');

    Route::post('settings/update/verify', [SystemUpdateController::class, 'verify'])
        ->middleware([
            RequirePassword::class,
            EnsureSystemOwner::class,
            EnsureNoConflictingLifecycleRecovery::class,
            'throttle:update-verify',
        ])
        ->name('settings.update.verify');

    Route::post('settings/update/confirm', [SystemUpdateController::class, 'confirm'])
        ->middleware([RequirePassword::class, EnsureSystemOwner::class, 'throttle:update-verify'])
        ->name('settings.update.confirm');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
