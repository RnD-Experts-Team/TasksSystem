<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TodoController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('workspaces/{workspaceId}/todos', [TodoController::class, 'index']);
    Route::post('workspaces/{workspaceId}/todos', [TodoController::class, 'store']);
    Route::get('workspaces/{workspaceId}/todos/{todo}', [TodoController::class, 'show']);
    Route::put('workspaces/{workspaceId}/todos/{todo}', [TodoController::class, 'update']);
    Route::delete('workspaces/{workspaceId}/todos/{todo}', [TodoController::class, 'destroy']);
});