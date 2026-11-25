<?php

declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Schemy\Http\Controllers\IdentityController;
use JustinHoltWeb\Schemy\Http\Controllers\SectionsController;
use JustinHoltWeb\Schemy\Http\Controllers\ValidatorController;
use JustinHoltWeb\Schemy\Utilities\ValidatorUtility;

use function CraftCms\Cms\cp_url;

// The group already applies web, auth and can:accessCp, under the CP trigger.

Route::middleware(RequireAdmin::class)->group(function (): void {
    Route::get('schemy', fn () => redirect(cp_url('schemy/sections')));
    Route::get('schemy/sections', [SectionsController::class, 'index']);
    Route::get('schemy/sections/{sectionId}', [SectionsController::class, 'edit'])->whereNumber('sectionId');
    Route::get('schemy/identity', [IdentityController::class, 'edit']);
    // Schemy 5's settings screen
    Route::get('schemy/settings', fn () => redirect(cp_url('settings/plugins/schemy')));

    Route::middleware(RequireAdminChanges::class)->group(function (): void {
        Route::post('schemy/sections/{sectionId}', [SectionsController::class, 'save'])->whereNumber('sectionId');
        Route::post('schemy/identity', [IdentityController::class, 'save']);
    });
});

Route::post('schemy/validator', ValidatorController::class)
    ->middleware('can:utility:'.ValidatorUtility::id());
