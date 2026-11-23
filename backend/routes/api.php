<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\RangeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Pakistani Lab LIMS            (prefix: /api)
|--------------------------------------------------------------------------
| Public: health, first-run setup, login.   Everything else needs a Sanctum token.
| Roles: admin | pathologist | technician | receptionist (see docs/SECURITY.md).
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Pakistani World-Class Lab Management System',
        'time' => now('Asia/Karachi')->toDateTimeString(),
    ]);
});

// First-run setup + login (public)
Route::get('/setup/status', [SetupController::class, 'status']);
Route::post('/setup', [SetupController::class, 'store']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Session
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'summary']);

    // Catalog (read)
    Route::get('/catalog/search', [CatalogController::class, 'search']);
    Route::get('/catalog/departments', [CatalogController::class, 'departments']);
    Route::get('/catalog/{id}', [CatalogController::class, 'show']);

    // Reference ranges: review status (everyone), management (admin + pathologist), verification (pathologist only)
    Route::get('/review/catalog', [RangeController::class, 'reviewQueue']);
    Route::middleware('role:admin,pathologist')->group(function () {
        Route::post('/parameters/{parameterId}/ranges', [RangeController::class, 'store']);
        Route::put('/ranges/{id}', [RangeController::class, 'update']);
        Route::delete('/ranges/{id}', [RangeController::class, 'destroy']);
    });
    Route::middleware('role:pathologist')->group(function () {
        Route::post('/ranges/{id}/verify', [RangeController::class, 'verify']);
        Route::post('/ranges/{id}/unverify', [RangeController::class, 'unverify']);
        Route::post('/catalog/{id}/verify', [RangeController::class, 'verifyTest']);
    });

    // Patients
    Route::get('/patients', [PatientController::class, 'index']);
    Route::post('/patients', [PatientController::class, 'store']);
    Route::get('/patients/{id}', [PatientController::class, 'show']);
    Route::put('/patients/{id}', [PatientController::class, 'update']);
    Route::delete('/patients/{id}', [PatientController::class, 'destroy'])->middleware('role:admin');

    // Reports
    Route::get('/reports', [ReportController::class, 'index']);
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    Route::middleware('role:admin,pathologist,technician')->group(function () {
        Route::put('/reports/{id}/results', [ReportController::class, 'updateResults']);
        Route::post('/reports/{id}/finalize', [ReportController::class, 'finalize']);
    });

    // Laboratory settings (read: everyone, change: admin). Reports are rendered by the app itself
    // (frontend/src/domain/reportHtml.ts) so they also work offline.
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('role:admin');

    // Google Drive sync
    Route::get('/sync/status', [SyncController::class, 'status'])->middleware('role:admin,pathologist');
    Route::post('/sync/run', [SyncController::class, 'run'])->middleware('role:admin');

    // Staff accounts (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
    });
});
