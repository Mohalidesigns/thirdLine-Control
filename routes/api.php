<?php

use App\Http\Controllers\Api\AssuranceApiController;
use App\Http\Controllers\Api\ComplianceApiController;
use App\Http\Controllers\Api\IntegrationApiController;
use Illuminate\Support\Facades\Route;

// Throttle sits BEFORE authentication so invalid-key probing is rate
// limited too (DEF-019).
Route::prefix('v1')->middleware(['throttle:integration-api', 'integration.auth'])->group(function () {
    Route::get('controls', [IntegrationApiController::class, 'controls']);
    Route::post('controls', [IntegrationApiController::class, 'upsertControl']);
    Route::get('test-results', [IntegrationApiController::class, 'testResults']);
    Route::get('exceptions', [IntegrationApiController::class, 'exceptions']);

    // Phase 8 — read-only compliance surface
    Route::get('frameworks/coverage', [ComplianceApiController::class, 'frameworkCoverage']);
    Route::get('obligations/instances', [ComplianceApiController::class, 'obligationInstances']);

    // Phase 17.5 — the internal-audit interlock. Coverage and gaps read
    // out, reliance decisions and audit findings write in; a finding
    // arriving here becomes a control exception in the ordinary register
    // with the ordinary lifecycle, not a parallel one.
    // CR — the Speak Up Metadata Access Log, read out to ThirdLine so
    // internal audit can inspect every break-glass reveal independently.
    // The payload names people, reasons and fields — never a metadata
    // value: the integration key carries no reveal-level scope, so Tier 2
    // stays inside the platform.
    Route::get('speak-up/metadata-access-log', [IntegrationApiController::class, 'speakUpMetadataAccessLog']);

    Route::get('assurance/coverage', [AssuranceApiController::class, 'coverage']);
    Route::get('assurance/gaps', [AssuranceApiController::class, 'gaps']);
    Route::post('assurance/activities', [AssuranceApiController::class, 'upsertActivity']);
    Route::post('assurance/findings', [AssuranceApiController::class, 'pushFinding']);
    Route::get('third-parties', [AssuranceApiController::class, 'thirdParties']);
});
