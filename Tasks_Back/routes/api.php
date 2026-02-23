<?php
// routes/api.php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Load all route files
Route::prefix('')->group(function () {
    require __DIR__ . '/api/auth.php';
    require __DIR__ . '/api/clocking.php';
    require __DIR__ . '/api/users.php';
    require __DIR__ . '/api/roles.php';
    require __DIR__ . '/api/permissions.php';
    require __DIR__ . '/api/projects.php';
    require __DIR__ . '/api/sections.php';
    require __DIR__ . '/api/tasks.php';
    require __DIR__ . '/api/subtasks.php';
    require __DIR__ . '/api/help-requests.php';
    require __DIR__ . '/api/tickets.php';
    require __DIR__ . '/api/ratings.php';
    require __DIR__ . '/api/kanban.php';
    require __DIR__ . '/api/profile.php';
    require __DIR__ . '/api/dashboard.php';
    require __DIR__ . '/api/final-ratings.php';
    require __DIR__ . '/api/workspaces.php'; 
    require __DIR__ . '/api/todos.php';

});
