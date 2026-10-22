<?php

use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::inertia('onboarding', 'onboarding/index')->name('onboarding');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('workspaces/{workspace}/projects', [ProjectController::class, 'index'])
        ->name('workspaces.projects.index');
    Route::post('workspaces/{workspace}/projects', [ProjectController::class, 'store'])
        ->name('workspaces.projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])
        ->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])
        ->name('projects.update');
});

require __DIR__.'/settings.php';
