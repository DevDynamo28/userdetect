<?php

use App\Http\Controllers\Dashboard\AnalyticsDashboardController;
use App\Http\Controllers\Dashboard\ApiKeyController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\ApiTesterController;
use App\Http\Controllers\Dashboard\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Root redirect
Route::get('/', fn () => redirect()->route('dashboard.home'));

// Authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Dashboard (requires authentication)
Route::middleware('auth')->prefix('dashboard')->name('dashboard.')->group(function () {
    // Home
    Route::get('/', [DashboardController::class, 'index'])->name('home');

    // Analytics
    Route::get('/analytics', [AnalyticsDashboardController::class, 'index'])->name('analytics');
    Route::get('/analytics/export', [AnalyticsDashboardController::class, 'export'])->name('analytics.export');

    // API Keys
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/api-keys/regenerate', [ApiKeyController::class, 'regenerate'])->name('api-keys.regenerate');
    Route::post('/api-keys/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');

    // API Tester
    Route::get('/api-tester', [ApiTesterController::class, 'index'])->name('api-tester');
    Route::post('/api-tester/test', [ApiTesterController::class, 'test'])->name('api-tester.test');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

    // Documentation
    Route::get('/docs', fn () => view('dashboard.docs'))->name('docs');
});
