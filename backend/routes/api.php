<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CatalogController;

/*
|--------------------------------------------------------------------------
| API Routes – Pakistani Lab LIMS
|--------------------------------------------------------------------------
|
| Prefix: /api
| All routes are currently open for local development.
| Sanctum auth will be added in a later stage.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'Pakistani World-Class Lab Management System',
        'time' => now('Asia/Karachi')->toDateTimeString(),
    ]);
});

// Patients
Route::get('/patients', [PatientController::class, 'index']);
Route::post('/patients', [PatientController::class, 'store']);
Route::get('/patients/{id}', [PatientController::class, 'show']);
Route::put('/patients/{id}', [PatientController::class, 'update']);
Route::delete('/patients/{id}', [PatientController::class, 'destroy']);

// Reports
Route::get('/reports', [ReportController::class, 'index']);
Route::post('/reports', [ReportController::class, 'store']);
Route::get('/reports/{id}', [ReportController::class, 'show']);
Route::put('/reports/{id}/results', [ReportController::class, 'updateResults']);
Route::post('/reports/{id}/finalize', [ReportController::class, 'finalize']);

// Catalog
Route::get('/catalog/search', [CatalogController::class, 'search']);
Route::get('/catalog/departments', [CatalogController::class, 'departments']);
Route::get('/catalog/{id}', [CatalogController::class, 'show']);
