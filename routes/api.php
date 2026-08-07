<?php

use App\Http\Controllers\Api\IntegrationApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('integration.auth')->group(function () {
    Route::get('controls', [IntegrationApiController::class, 'controls']);
    Route::post('controls', [IntegrationApiController::class, 'upsertControl']);
    Route::get('test-results', [IntegrationApiController::class, 'testResults']);
    Route::get('exceptions', [IntegrationApiController::class, 'exceptions']);
});
