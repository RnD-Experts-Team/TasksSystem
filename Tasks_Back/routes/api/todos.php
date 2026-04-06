<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TodoController;

Route::middleware('auth:sanctum')->group(function () {

    Route::get('workspaces/{workspaceId}/todos', [TodoController::class, 'index']);
    Route::post('todos', [TodoController::class, 'store']);
    Route::get('todos/{todo}', [TodoController::class, 'show']);
    Route::put('todos/{todo}', [TodoController::class, 'update']);
    Route::delete('todos/{todo}', [TodoController::class, 'destroy']);
});
