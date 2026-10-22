<?php

use App\Http\Controllers\BulkKeywordStatusController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\KeywordChangeSetController;
use App\Http\Controllers\KeywordGridController;
use App\Http\Controllers\KeywordImportController;
use App\Http\Controllers\KeywordIntelligenceController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkspaceController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SavedKeywordViewController;
use App\Support\ProjectWorkspaceContext;
use Illuminate\Support\Facades\Route;

Route::get('install', [InstallerController::class, 'index'])->name('install.index');
Route::post('install/environment', [InstallerController::class, 'saveEnvironment'])
    ->middleware('throttle:installer')
    ->name('install.environment.store');
Route::post('install/database/verify', [InstallerController::class, 'verifyDatabase'])
    ->middleware('throttle:installer')
    ->name('install.database.verify');
Route::post('install/migrations/run', [InstallerController::class, 'runMigrations'])
    ->middleware('throttle:installer')
    ->name('install.migrations.run');
Route::post('install/owner', [InstallerController::class, 'createOwner'])
    ->middleware('throttle:installer')
    ->name('install.owner.store');
Route::post('install/operations/probe', [InstallerController::class, 'dispatchQueueProbe'])
    ->middleware('throttle:installer')
    ->name('install.operations.probe');
Route::post('install/operations/continue', [InstallerController::class, 'continueOperations'])
    ->middleware('throttle:installer')
    ->name('install.operations.continue');
Route::post('install/finalize', [InstallerController::class, 'finalize'])
    ->middleware('throttle:installer')
    ->name('install.finalize');
Route::inertia('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::inertia('onboarding', 'onboarding/index')->name('onboarding');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('workspaces/{workspace}/projects', [ProjectController::class, 'index'])->name('workspaces.projects.index');
    Route::post('workspaces/{workspace}/projects', [ProjectController::class, 'store'])->name('workspaces.projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    Route::post('projects/{project}/keyword-imports/stage', [KeywordImportController::class, 'store'])->name('projects.keyword-imports.stage');
    Route::post('projects/{project}/keyword-views', [SavedKeywordViewController::class, 'store'])->name('projects.keyword-views.store');
    Route::patch('projects/{project}/keyword-views/{savedKeywordView}', [SavedKeywordViewController::class, 'update'])->name('projects.keyword-views.update');
    Route::post('projects/{project}/keyword-views/{savedKeywordView}/default', [SavedKeywordViewController::class, 'setDefault'])->name('projects.keyword-views.default');
    Route::patch('projects/{project}/keywords/bulk-status', BulkKeywordStatusController::class)->name('projects.keywords.bulk-status');
    Route::post('projects/{project}/keyword-change-sets/{changeSet}/undo', [KeywordChangeSetController::class, 'undo'])->name('projects.keyword-change-sets.undo');

    Route::get('projects/{project}/keyword-intelligence/clusters/search', [KeywordIntelligenceController::class, 'searchClusters'])->name('projects.keyword-intelligence.clusters.search');
    Route::post('projects/{project}/keyword-intelligence/clusters', [KeywordIntelligenceController::class, 'storeCluster'])->name('projects.keyword-intelligence.clusters.store');
    Route::get('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/members/search', [KeywordIntelligenceController::class, 'searchClusterMembers'])->name('projects.keyword-intelligence.cluster-members.search');
    Route::post('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/merge', [KeywordIntelligenceController::class, 'mergeClusters'])->name('projects.keyword-intelligence.clusters.merge');
    Route::post('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/members/{keyword}/move', [KeywordIntelligenceController::class, 'moveClusterMember'])->name('projects.keyword-intelligence.cluster-members.move');
    Route::post('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/split', [KeywordIntelligenceController::class, 'splitCluster'])->name('projects.keyword-intelligence.clusters.split');
    Route::patch('projects/{project}/keyword-intelligence/clusters/{keywordCluster}', [KeywordIntelligenceController::class, 'updateCluster'])->name('projects.keyword-intelligence.clusters.update');
    Route::patch('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/lock', [KeywordIntelligenceController::class, 'setLock'])->name('projects.keyword-intelligence.clusters.lock');
    Route::post('projects/{project}/keyword-intelligence/pages', [KeywordIntelligenceController::class, 'storePage'])->name('projects.keyword-intelligence.pages.store');
    Route::get('projects/{project}/keyword-intelligence/pages/search', [KeywordIntelligenceController::class, 'searchPages'])->name('projects.keyword-intelligence.pages.search');
    Route::put('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/target', [KeywordIntelligenceController::class, 'mapTarget'])->name('projects.keyword-intelligence.clusters.target');
    Route::post('projects/{project}/keyword-intelligence/clusters/{keywordCluster}/ready', [KeywordIntelligenceController::class, 'markReady'])->name('projects.keyword-intelligence.clusters.ready');
    Route::post('projects/{project}/keyword-intelligence/cluster-suggestions/refresh', [KeywordIntelligenceController::class, 'refreshClusterSuggestions'])->middleware('throttle:4,1')->name('projects.keyword-intelligence.cluster-suggestions.refresh');
    Route::post('projects/{project}/keyword-intelligence/mapping-conflicts/refresh', [KeywordIntelligenceController::class, 'refreshMappingConflicts'])->middleware('throttle:4,1')->name('projects.keyword-intelligence.mapping-conflicts.refresh');

    Route::get('projects/{project}/reviews', [ReviewQueueController::class, 'index'])->name('projects.reviews.index');
    Route::post('projects/{project}/reviews/refresh', [ReviewQueueController::class, 'refresh'])->middleware('throttle:6,1')->name('projects.reviews.refresh');
    Route::post('projects/{project}/reviews/{reviewItem}/resolve', [ReviewQueueController::class, 'resolve'])->name('projects.reviews.resolve');

    Route::get('projects/{project}/overview', [ProjectWorkspaceController::class, 'overview'])->name('projects.overview');
    Route::get('projects/{project}/keywords', [KeywordGridController::class, 'index'])->name('projects.keywords.index');
    Route::get('projects/{project}/{section}', [ProjectWorkspaceController::class, 'section'])
        ->whereIn('section', array_values(array_filter(ProjectWorkspaceContext::SECTIONS, fn (string $section): bool => !in_array($section, ['overview', 'keywords', 'reviews'], true))))
        ->name('projects.section');
});

require __DIR__.'/settings.php';
