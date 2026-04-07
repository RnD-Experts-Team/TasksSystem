<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Management Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {
    
    // View users
    Route::middleware(['permission:view users'])->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{id}', [UserController::class, 'show'])->name('users.show');
        // Route::get('users/{id}/with-projects', [UserController::class, 'showWithProjects'])->name('users.with-projects');
        Route::get('users/{id}/roles-and-permissions', [UserController::class, 'getRolesAndPermissions'])->name('users.roles-permissions');
        Route::get('users/{id}/projects', [UserController::class, 'getProjects'])->name('users.projects');
        Route::get('users/{id}/task-assignments', [UserController::class, 'getTaskAssignments'])->name('users.task-assignments');
    });
    
    // Create users
    Route::middleware(['permission:create users'])->group(function () {
        Route::post('users', [UserController::class, 'store'])->name('users.store');
    });
    
    // Edit users
    Route::middleware(['permission:edit users'])->group(function () {
        Route::put('users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{id}/sync-roles', [UserController::class, 'syncRoles'])->name('users.sync-roles');
        Route::post('users/{id}/sync-permissions', [UserController::class, 'syncPermissions'])->name('users.sync-permissions');
        Route::post('users/{id}/sync-roles-and-permissions', [UserController::class, 'syncRolesAndPermissions'])->name('users.sync-roles-permissions');
    });
    
    // Delete users
    Route::middleware(['permission:delete users'])->group(function () {
        Route::delete('users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
