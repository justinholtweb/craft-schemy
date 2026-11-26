<?php

declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Schemy\Http\Controllers\IdentityController;
use JustinHoltWeb\Schemy\Http\Controllers\SectionsController;
use JustinHoltWeb\Schemy\Http\Controllers\ValidatorController;
use JustinHoltWeb\Schemy\Plugin;
use JustinHoltWeb\Schemy\Utilities\ValidatorUtility;

use function CraftCms\Cms\cp_url;

// The group already applies web, auth and can:accessCp, under the CP trigger. Section schema and
// the identity are stored in Schemy's table, not project config, so they can be saved wherever
// the user has the permission, whatever `allowAdminChanges` is.

Route::middleware('can:accessPlugin-schemy')->group(function (): void {
    // Land on the first screen the user can use
    Route::get('schemy', fn () => redirect(cp_url(
        Gate::allows(Plugin::PERMISSION_EDIT_SECTIONS) || Gate::denies(Plugin::PERMISSION_EDIT_IDENTITY)
            ? 'schemy/sections'
            : 'schemy/identity',
    )));

    Route::middleware('can:'.Plugin::PERMISSION_EDIT_SECTIONS)->group(function (): void {
        Route::get('schemy/sections', [SectionsController::class, 'index']);
        Route::get('schemy/sections/{sectionId}', [SectionsController::class, 'edit'])->whereNumber('sectionId');
        Route::post('schemy/sections/{sectionId}', [SectionsController::class, 'save'])->whereNumber('sectionId');
    });

    Route::middleware('can:'.Plugin::PERMISSION_EDIT_IDENTITY)->group(function (): void {
        Route::get('schemy/identity', [IdentityController::class, 'edit']);
        Route::post('schemy/identity', [IdentityController::class, 'save']);
    });
});

// Schemy 5's settings screen
Route::get('schemy/settings', fn () => redirect(cp_url('settings/plugins/schemy')))
    ->middleware(RequireAdmin::class);

Route::post('schemy/validator', ValidatorController::class)
    ->middleware('can:utility:'.ValidatorUtility::id());
