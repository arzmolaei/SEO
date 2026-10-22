<?php

use App\Http\Controllers\KeywordGridController;
use App\Http\Controllers\KeywordImportController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkspaceController;
use App\Support\ProjectWorkspaceContext;
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

    Route::post('projects/{project}/keyword-imports/stage', [KeywordImportController::class, 'store'])
        ->name('projects.keyword-imports.stage');

    Route::get('projects/{project}/overview', [ProjectWorkspaceController::class, 'overview'])
        ->name('projects.overview');
    Route::get('projects/{project}/keywords', [KeywordGridController::class, 'index'])
        ->name('projects.keywords.index');
    Route::get('projects/{project}/{section}', [ProjectWorkspaceController::class, 'section'])
        ->whereIn('section', array_values(array_filter(
            ProjectWorkspaceContext::SECTIONS,
            fn (string $section): bool => ! in_array($section, ['overview', 'keywords'], true),
        )))
        ->name('projects.section');
});

require __DIR__.'/settings.php';
