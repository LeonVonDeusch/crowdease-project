<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\RouteController;
use App\Http\Controllers\Api\V1\Admin\StopController;
use App\Http\Controllers\Api\V1\Admin\VehicleController;
use App\Http\Controllers\Api\V1\Admin\ApiKeyController;
use App\Http\Controllers\Api\V1\Admin\WebhookController;

// Semua operator bisa akses
Route::middleware(['auth:sanctum', 'role'])->group(function () {

    // Auth
    Route::post('auth/login', [AuthController::class, 'login'])->withoutMiddleware(['auth:sanctum', 'role']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('dashboard/hourly', [DashboardController::class, 'hourly']);

    // Resources
    Route::apiResource('routes', RouteController::class);
    Route::apiResource('routes.stops', StopController::class)->except('show');
    Route::apiResource('vehicles', VehicleController::class);

    // API Keys — hanya admin
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('api-keys', ApiKeyController::class)->only(['index', 'store', 'destroy']);
    });

    // Webhooks
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test']);
    Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
});

// Hanya admin
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    // Tambahkan route hanya untuk admin di sini...
});

