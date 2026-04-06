<?php
// routes/api/clocking.php

use App\Http\Controllers\ClockingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('clocking')->group(function () {
    // ========================================
    // EMPLOYEE ENDPOINTS
    // ========================================

    // OPTIMIZED: Single endpoint for initial page load
    Route::get('/initial-data', [ClockingController::class, 'getInitialData']);

    // Actions
    Route::post('/clock-in', [ClockingController::class, 'clockIn']);
    Route::post('/clock-out', [ClockingController::class, 'clockOut']);
    Route::post('/break/start', [ClockingController::class, 'startBreak']);
    Route::post('/break/end', [ClockingController::class, 'endBreak']);

    // Records
    Route::get('/records', [ClockingController::class, 'getRecords']);
    Route::post('/export', [ClockingController::class, 'exportRecords']);

    Route::post('/correction-request', [ClockingController::class, 'requestCorrection']);
    Route::get('/pending-corrections', [ClockingController::class, 'getPendingCorrections']);
    // ========================================
    // MANAGER ENDPOINTS
    // ========================================

    Route::middleware('can:view all clocking sessions')->prefix('manager')->group(function () {
        // OPTIMIZED: Single endpoint for initial dashboard load
        Route::get('/initial-data', [ClockingController::class, 'getManagerInitialData']);
        Route::get('/pending-corrections', [ClockingController::class, 'getAllPendingCorrections']);
        Route::post('/correction/{correctionId}/handle', [ClockingController::class, 'handleCorrection']);
        Route::put('/session/{sessionId}/edit', [ClockingController::class, 'directEditClockSession']);
        Route::put('/break/{breakId}/edit', [ClockingController::class, 'directEditBreakRecord']);
        // Additional endpoints
        Route::get('/all-records', [ClockingController::class, 'getAllRecords']);
        Route::post('/export-all', [ClockingController::class, 'exportAllRecords']);
    });
});
