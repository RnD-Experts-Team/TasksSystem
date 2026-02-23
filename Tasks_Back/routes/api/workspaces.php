<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkspaceController;

Route::middleware('auth:sanctum')->group(function () {

    Route::apiResource('workspaces', WorkspaceController::class)
        ->except(['index']);
    
     Route::post('workspaces/{workspaceId}/users', 
        [WorkspaceController::class, 'addUser']);

    Route::delete('workspaces/{workspaceId}/users/{userId}', 
        [WorkspaceController::class, 'removeUser']);

    Route::put('workspaces/{workspaceId}/users/{userId}/role', 
        [WorkspaceController::class, 'updateRole']);

    Route::get('workspaces/{workspaceId}/users', 
        [WorkspaceController::class, 'members']);
});

 
