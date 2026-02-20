<?php

use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ClientController;
use App\Http\Controllers\API\DetectionController;
use App\Http\Controllers\API\LocationVerificationController;
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
    Route::post('/user/{fingerprintId}/verify-location', [LocationVerificationController::class, 'store']);

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
        'domain' => parse_url(config('app.url'), PHP_URL_HOST) ?? 'unknown',
        'slo_target_ms' => (int) config('detection.slo.detect_p95_ms', 700),
        'timestamp' => now()->toIso8601String(),
    ]);
});
