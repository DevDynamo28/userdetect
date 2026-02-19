<?php

use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ClientController;
use App\Http\Controllers\API\DetectionController;
use App\Http\Controllers\API\UserHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| Full URL: /api/v1/detect, /api/v1/analytics/summary, etc.
|
*/

Route::prefix('v1')->middleware(['api.key', 'api.rate'])->group(function () {
    // Primary detection endpoint
    Route::post('/detect', [DetectionController::class, 'detect']);

    // User history
    Route::get('/user/{fingerprintId}/history', [UserHistoryController::class, 'show']);

    // Analytics
    Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);

    // Domain verification
    Route::post('/client/verify-domain', [ClientController::class, 'verifyDomain']);
});

// Health check (no auth required)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'UserDetect API',
        'domain' => 'devdemosite.live',
        'timestamp' => now()->toIso8601String(),
    ]);
});
