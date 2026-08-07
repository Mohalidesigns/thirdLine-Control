<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\CompensatingControlController;
use App\Http\Controllers\ContentPackController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\ControlRequirementMapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EscalationMatrixController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExceptionController;
use App\Http\Controllers\FeatureFlagController;
use App\Http\Controllers\FrameworkController;
use App\Http\Controllers\FrameworkRequirementController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ObligationAssignmentController;
use App\Http\Controllers\ObligationController;
use App\Http\Controllers\ObligationInstanceController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\RegulatoryChangeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpotCheckController;
use App\Http\Controllers\SsoConfigurationController;
use App\Http\Controllers\TenantBrandingController;
use App\Http\Controllers\TenantSecurityController;
use App\Http\Controllers\TestInstanceController;
use App\Http\Controllers\TestScriptController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// ── PWA shell (Phase 7.10) — public by design
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');

// ── Guest ────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

// ── SSO login flows (Phase 7.1) — session created only on a valid assertion
Route::middleware('feature:sso')->group(function () {
    Route::get('auth/sso/{sso_configuration}/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('auth/sso/{sso_configuration}/callback', [SsoController::class, 'callback'])->name('sso.callback');
    Route::post('auth/sso/{sso_configuration}/acs', [SsoController::class, 'acs'])->name('sso.acs');
    Route::get('auth/sso/{sso_configuration}/metadata', [SsoController::class, 'metadata'])->name('sso.metadata');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // ── Dashboard (all roles) ────────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Notifications ────────────────────────────────────────────────
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // ── MFA (Phase 7.2) ──────────────────────────────────────────────
    Route::middleware('feature:mfa')->group(function () {
        Route::get('mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
        Route::post('mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:6,1')->name('mfa.verify');
        Route::post('mfa/email-otp', [MfaController::class, 'sendEmailOtp'])->middleware('throttle:3,10')->name('mfa.email-otp');
        Route::get('settings/security', [MfaController::class, 'setup'])->name('mfa.setup');
        Route::post('mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');
        Route::delete('mfa', [MfaController::class, 'disable'])->name('mfa.disable');
    });

    // ── Settings (own account) ───────────────────────────────────────
    Route::middleware('feature:notification-preferences')->group(function () {
        Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('settings.notifications');
        Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('settings.notifications.update');
    });

    // ── Control library ──────────────────────────────────────────────
    // Templates before the resource so 'templates' isn't captured as a {control}.
    Route::middleware('role:System Administrator|Control Function Head|Control Officer')->group(function () {
        Route::get('controls/templates', [ControlController::class, 'templates'])->name('controls.templates');
        Route::post('controls/templates/{template}/adopt', [ControlController::class, 'adopt'])->name('controls.adopt');
    });

    Route::resource('controls', ControlController::class);
    Route::post('controls/{control}/submit', [ControlController::class, 'submit'])->name('controls.submit');
    Route::post('controls/{control}/approve', [ControlController::class, 'approve'])->name('controls.approve');
    Route::post('controls/{control}/reject', [ControlController::class, 'reject'])->name('controls.reject');
    Route::post('controls/{control}/retire', [ControlController::class, 'retire'])->name('controls.retire');
    Route::post('controls/{control}/assess', [ControlController::class, 'assess'])->name('controls.assess');

    // ── Risk register & mapping ──────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head|Control Officer|Executive Viewer|Line Manager')->group(function () {
        Route::get('risks/gaps', [RiskController::class, 'gaps'])->name('risks.gaps');
        Route::resource('risks', RiskController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('risks/{risk}/controls', [RiskController::class, 'attachControl'])->name('risks.controls.attach');
        Route::delete('risks/{risk}/controls/{control}', [RiskController::class, 'detachControl'])->name('risks.controls.detach');
    });

    // ── Test scripts ─────────────────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head|Control Officer')->group(function () {
        Route::resource('test-scripts', TestScriptController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('test-scripts/{test_script}/approve', [TestScriptController::class, 'approve'])->name('test-scripts.approve');
    });

    // ── Control testing ──────────────────────────────────────────────
    Route::resource('test-instances', TestInstanceController::class)->only(['index', 'show']);
    Route::post('test-instances/{test_instance}/start', [TestInstanceController::class, 'start'])->name('test-instances.start');
    Route::post('test-instances/{test_instance}/results', [TestInstanceController::class, 'recordResult'])->name('test-instances.results');
    Route::post('test-instances/{test_instance}/submit', [TestInstanceController::class, 'submit'])->name('test-instances.submit');
    Route::post('test-instances/{test_instance}/review', [TestInstanceController::class, 'review'])->name('test-instances.review');
    Route::post('test-instances/{test_instance}/reopen', [TestInstanceController::class, 'reopen'])->name('test-instances.reopen');
    Route::post('test-instances/{test_instance}/rate', [TestInstanceController::class, 'rate'])->name('test-instances.rate');
    Route::post('test-instances/{test_instance}/approve-rating', [TestInstanceController::class, 'approveRating'])->name('test-instances.approve-rating');

    // ── Exceptions ───────────────────────────────────────────────────
    Route::resource('exceptions', ExceptionController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['exceptions' => 'exception']);
    Route::post('exceptions/{exception}/assign', [ExceptionController::class, 'assign'])->name('exceptions.assign');
    Route::post('exceptions/{exception}/in-progress', [ExceptionController::class, 'markInProgress'])->name('exceptions.in-progress');
    Route::post('exceptions/{exception}/remediated', [ExceptionController::class, 'markRemediated'])->name('exceptions.remediated');
    Route::post('exceptions/{exception}/close', [ExceptionController::class, 'close'])->name('exceptions.close');
    Route::post('exceptions/{exception}/fail-verification', [ExceptionController::class, 'failVerification'])->name('exceptions.fail-verification');
    Route::post('exceptions/{exception}/request-extension', [ExceptionController::class, 'requestExtension'])->name('exceptions.request-extension');
    Route::post('exceptions/{exception}/decide-extension', [ExceptionController::class, 'decideExtension'])->name('exceptions.decide-extension');
    Route::post('exceptions/{exception}/accept-risk', [ExceptionController::class, 'acceptRisk'])->name('exceptions.accept-risk');
    Route::post('exceptions/{exception}/comments', [ExceptionController::class, 'comment'])->name('exceptions.comments');

    // ── Compensating controls ────────────────────────────────────────
    Route::get('compensating-controls', [CompensatingControlController::class, 'index'])->name('compensating-controls.index');
    Route::post('exceptions/{exception}/compensating-controls', [CompensatingControlController::class, 'store'])->name('compensating-controls.store');
    Route::post('compensating-controls/{compensating_control}/approve', [CompensatingControlController::class, 'approve'])->name('compensating-controls.approve');
    Route::post('compensating-controls/{compensating_control}/withdraw', [CompensatingControlController::class, 'withdraw'])->name('compensating-controls.withdraw');

    // ── Spot checks ──────────────────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head|Control Officer')->group(function () {
        Route::resource('spot-checks', SpotCheckController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('spot-checks/{spot_check}/findings', [SpotCheckController::class, 'storeFinding'])->name('spot-checks.findings.store');
        Route::put('spot-checks/{spot_check}/findings/{finding}', [SpotCheckController::class, 'updateFinding'])->name('spot-checks.findings.update');
        Route::post('spot-checks/{spot_check}/complete', [SpotCheckController::class, 'complete'])->name('spot-checks.complete');
        Route::post('spot-checks/{spot_check}/issue-report', [SpotCheckController::class, 'issueReport'])->name('spot-checks.issue-report');
    });

    // ── Reports & exports (FR-10.7, FR-4.6, FR-10.8) ─────────────────
    Route::middleware('permission:export reports')->prefix('reports')->group(function () {
        Route::get('spot-checks/{spot_check}.pdf', [ReportController::class, 'spotCheck'])->name('reports.spot-check');
        Route::get('exceptions.pdf', [ReportController::class, 'exceptionRegisterPdf'])->name('reports.exceptions.pdf');
        Route::get('exceptions.xlsx', [ReportController::class, 'exceptionsXlsx'])->name('reports.exceptions.xlsx');
        Route::get('controls.xlsx', [ReportController::class, 'controlsXlsx'])->name('reports.controls.xlsx');
        Route::get('testing-summary.pdf', [ReportController::class, 'testingSummaryPdf'])->name('reports.testing-summary');
        Route::get('test-instances.xlsx', [ReportController::class, 'testInstancesXlsx'])->name('reports.test-instances.xlsx');
        Route::get('board-pack', [ReportController::class, 'boardPack'])->name('reports.board-pack');
    });

    // ── Frameworks & requirement mapping (Phase 8) ───────────────────
    Route::middleware('feature:frameworks')->group(function () {
        // Static segments first so they are not captured as {framework}.
        Route::get('frameworks/coverage-summary', [FrameworkController::class, 'coverageSummary'])
            ->name('frameworks.coverage-summary');
        Route::post('frameworks/import', [FrameworkController::class, 'import'])->name('frameworks.import');
        Route::get('frameworks/{framework}/coverage', [FrameworkController::class, 'coverage'])->name('frameworks.coverage');
        Route::get('frameworks/{framework}/export', [FrameworkController::class, 'export'])->name('frameworks.export');
        Route::resource('frameworks', FrameworkController::class)->except(['edit']);

        Route::post('frameworks/{framework}/requirements/reorder', [FrameworkRequirementController::class, 'reorder'])
            ->name('frameworks.requirements.reorder');
        Route::post('frameworks/{framework}/requirements', [FrameworkRequirementController::class, 'store'])
            ->name('frameworks.requirements.store');
        Route::put('frameworks/{framework}/requirements/{requirement}', [FrameworkRequirementController::class, 'update'])
            ->name('frameworks.requirements.update');
        Route::delete('frameworks/{framework}/requirements/{requirement}', [FrameworkRequirementController::class, 'destroy'])
            ->name('frameworks.requirements.destroy');
        Route::get('frameworks/{framework}/requirements/{requirement}/suggestions', [FrameworkRequirementController::class, 'suggestions'])
            ->name('frameworks.requirements.suggestions');

        Route::get('control-mappings', [ControlRequirementMapController::class, 'index'])->name('control-mappings.index');
        Route::post('control-mappings', [ControlRequirementMapController::class, 'store'])->name('control-mappings.store');
        Route::post('control-mappings/{mapping}/approve', [ControlRequirementMapController::class, 'approve'])->name('control-mappings.approve');
        Route::post('control-mappings/{mapping}/reject', [ControlRequirementMapController::class, 'reject'])->name('control-mappings.reject');
        Route::delete('control-mappings/{mapping}', [ControlRequirementMapController::class, 'destroy'])->name('control-mappings.destroy');
        Route::post('requirements/{requirement}/bulk-map', [ControlRequirementMapController::class, 'bulkStore'])->name('control-mappings.bulk');
        Route::post('framework-mappings/{framework_mapping}/verify', [ControlRequirementMapController::class, 'verifyLink'])
            ->name('framework-mappings.verify');
        Route::get('controls/{control}/frameworks', [ControlRequirementMapController::class, 'crossFramework'])
            ->name('controls.frameworks');
    });

    // ── Regulatory obligations & compliance calendar (Phase 8) ───────
    Route::middleware('feature:obligations')->group(function () {
        Route::get('obligations/calendar', [ObligationController::class, 'calendar'])->name('obligations.calendar');
        Route::get('obligations/instances', [ObligationInstanceController::class, 'index'])->name('obligations.instances.index');
        Route::get('obligations/instances/{instance}', [ObligationInstanceController::class, 'show'])->name('obligations.instances.show');
        Route::post('obligations/instances/{instance}/start', [ObligationInstanceController::class, 'start'])->name('obligations.instances.start');
        Route::post('obligations/instances/{instance}/submit', [ObligationInstanceController::class, 'submit'])->name('obligations.instances.submit');
        Route::post('obligations/instances/{instance}/review', [ObligationInstanceController::class, 'review'])->name('obligations.instances.review');
        Route::post('obligations/instances/{instance}/waive', [ObligationInstanceController::class, 'waive'])->name('obligations.instances.waive');

        Route::get('entities/{entity}/obligations', [ObligationController::class, 'applicability'])->name('entities.obligations');
        Route::post('entities/{entity}/obligations', [ObligationAssignmentController::class, 'bulkStore'])->name('entities.obligations.bulk');

        Route::resource('obligations', ObligationController::class)->except(['destroy']);

        Route::post('obligation-assignments', [ObligationAssignmentController::class, 'store'])->name('obligation-assignments.store');
        Route::put('obligation-assignments/{assignment}', [ObligationAssignmentController::class, 'update'])->name('obligation-assignments.update');
        Route::post('obligation-assignments/{assignment}/non-applicable', [ObligationAssignmentController::class, 'declareNonApplicable'])
            ->name('obligation-assignments.non-applicable');
        Route::post('obligation-assignments/{assignment}/approve-non-applicability', [ObligationAssignmentController::class, 'approveNonApplicability'])
            ->name('obligation-assignments.approve-non-applicability');
    });

    // ── Regulatory change feed (Phase 8) ─────────────────────────────
    Route::middleware('feature:regulatory-changes')->group(function () {
        Route::get('regulatory-changes', [RegulatoryChangeController::class, 'index'])->name('regulatory-changes.index');
        Route::post('regulatory-changes', [RegulatoryChangeController::class, 'store'])->name('regulatory-changes.store');
        Route::post('regulatory-changes/{change}/review', [RegulatoryChangeController::class, 'startReview'])->name('regulatory-changes.review');
        Route::post('regulatory-changes/{change}/assess', [RegulatoryChangeController::class, 'assess'])->name('regulatory-changes.assess');
        Route::post('regulatory-changes/{change}/action', [RegulatoryChangeController::class, 'action'])->name('regulatory-changes.action');
        Route::post('regulatory-changes/{change}/not-applicable', [RegulatoryChangeController::class, 'notApplicable'])
            ->name('regulatory-changes.not-applicable');
    });

    // ── Evidence (FR-9) ──────────────────────────────────────────────
    Route::post('evidence', [EvidenceController::class, 'store'])->name('evidence.store');
    Route::get('evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
    Route::post('evidence/{evidence}/legal-hold', [EvidenceController::class, 'legalHold'])->name('evidence.legal-hold');

    // ── Low-bandwidth mode toggle (Phase 7.10)
    Route::middleware('feature:low-bandwidth-mode')
        ->post('settings/low-bandwidth', [PwaController::class, 'toggleLowBandwidth'])
        ->name('settings.low-bandwidth');

    // ── Saved views (Phase 7.8)
    Route::middleware('feature:saved-views')->group(function () {
        Route::get('saved-views', [SavedViewController::class, 'index'])->name('saved-views.index');
        Route::post('saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
        Route::post('saved-views/{saved_view}/default', [SavedViewController::class, 'setDefault'])->name('saved-views.default');
        Route::delete('saved-views/{saved_view}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');
    });

    // ── Global search (Phase 7.7) — tenant- and permission-scoped
    Route::middleware(['feature:global-search', 'throttle:60,1'])
        ->get('search', SearchController::class)
        ->name('search');

    // ── Record activity history (Phase 7.5) — access mirrors the record's policy
    Route::middleware('feature:audit-log-ui')
        ->get('audit-trail/{alias}/{id}', [AuditLogController::class, 'entity'])
        ->name('audit-trail.entity');

    // ── Administration ───────────────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head')->prefix('admin')->group(function () {
        Route::get('escalation-matrix', [EscalationMatrixController::class, 'index'])->name('admin.escalation-matrix');
        Route::post('escalation-matrix', [EscalationMatrixController::class, 'store'])->name('admin.escalation-matrix.store');
        Route::put('escalation-matrix/{escalation_matrix}', [EscalationMatrixController::class, 'update'])->name('admin.escalation-matrix.update');
        Route::delete('escalation-matrix/{escalation_matrix}', [EscalationMatrixController::class, 'destroy'])->name('admin.escalation-matrix.destroy');

        Route::get('evidence-disposal', [EvidenceController::class, 'disposalQueue'])->name('admin.evidence-disposal');
        Route::post('evidence/{evidence}/approve-disposal', [EvidenceController::class, 'approveDisposal'])->name('admin.evidence.approve-disposal');
        Route::post('retention-policies', [EvidenceController::class, 'storePolicy'])->name('admin.retention-policies.store');

        Route::middleware('permission:manage settings')->group(function () {
            Route::get('feature-flags', [FeatureFlagController::class, 'index'])->name('admin.feature-flags');
            Route::put('feature-flags/{key}', [FeatureFlagController::class, 'update'])->name('admin.feature-flags.update');

            Route::get('security', [TenantSecurityController::class, 'edit'])->name('admin.security');
            Route::put('security', [TenantSecurityController::class, 'update'])->name('admin.security.update');

            Route::middleware('feature:branding')->group(function () {
                Route::get('branding', [TenantBrandingController::class, 'edit'])->name('admin.branding');
                Route::post('branding', [TenantBrandingController::class, 'update'])->name('admin.branding.update');
            });
        });

        Route::middleware(['permission:manage sso', 'feature:sso'])->group(function () {
            Route::get('sso', [SsoConfigurationController::class, 'index'])->name('admin.sso');
            Route::post('sso', [SsoConfigurationController::class, 'store'])->name('admin.sso.store');
            Route::put('sso/{sso_configuration}', [SsoConfigurationController::class, 'update'])->name('admin.sso.update');
            Route::post('sso/{sso_configuration}/approve', [SsoConfigurationController::class, 'approve'])->name('admin.sso.approve');
            Route::post('sso/{sso_configuration}/reject', [SsoConfigurationController::class, 'reject'])->name('admin.sso.reject');
            Route::post('sso/{sso_configuration}/test', [SsoConfigurationController::class, 'test'])->name('admin.sso.test');
        });

        Route::middleware(['permission:view audit log', 'feature:audit-log-ui'])->group(function () {
            Route::get('audit-log', [AuditLogController::class, 'index'])->name('admin.audit-log');
            Route::get('audit-log/export', [AuditLogController::class, 'export'])
                ->middleware('permission:export audit log')->name('admin.audit-log.export');
        });

        // Regulatory content packs (Phase 8) — installation is a platform
        // operation, gated on its own permission inside the admin area.
        Route::middleware('feature:content-packs')->group(function () {
            Route::get('content-packs', [ContentPackController::class, 'index'])->name('admin.content-packs');

            Route::middleware('permission:install content-packs')->group(function () {
                Route::post('content-packs/preview', [ContentPackController::class, 'preview'])->name('admin.content-packs.preview');
                Route::post('content-packs/install', [ContentPackController::class, 'install'])->name('admin.content-packs.install');
            });
        });

        Route::get('integrations', [IntegrationController::class, 'index'])->name('admin.integrations');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('admin.integrations.store');
        Route::post('integrations/sync-logs/{sync_log}/replay', [IntegrationController::class, 'replay'])->name('admin.integrations.replay');
    });
});
