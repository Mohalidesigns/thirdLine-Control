<?php

use App\Http\Controllers\Admin\AiGovernanceController;
use App\Http\Controllers\Admin\MessagingController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SpeakUpSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\AssuranceController;
use App\Http\Controllers\AtlasController;
use App\Http\Controllers\AttestationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForcePasswordController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\CompensatingControlController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ContentPackController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\ControlDistributionController;
use App\Http\Controllers\ControlFunctionController;
use App\Http\Controllers\ControlFunctionImportController;
use App\Http\Controllers\ControlRequirementMapController;
use App\Http\Controllers\ControlStructureController;
use App\Http\Controllers\CsaCampaignController;
use App\Http\Controllers\CsaResponseController;
use App\Http\Controllers\DashboardBuilderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataSourceController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\EscalationMatrixController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExceptionController;
use App\Http\Controllers\ExceptionManagerController;
use App\Http\Controllers\ExceptionResponseController;
use App\Http\Controllers\ExceptionRoutingController;
use App\Http\Controllers\FeatureFlagController;
use App\Http\Controllers\FrameworkController;
use App\Http\Controllers\FrameworkRequirementController;
use App\Http\Controllers\GroupDashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImprovementActionController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InitiativeController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LinkageController;
use App\Http\Controllers\MetricController;
use App\Http\Controllers\MobileTaskController;
use App\Http\Controllers\MonitoringDashboardController;
use App\Http\Controllers\MonitoringFindingController;
use App\Http\Controllers\MonitoringRuleController;
use App\Http\Controllers\MonitoringRunController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ObjectiveController;
use App\Http\Controllers\ObligationAssignmentController;
use App\Http\Controllers\ObligationController;
use App\Http\Controllers\ObligationInstanceController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PolicyExceptionController;
use App\Http\Controllers\PublicExceptionResponseController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\RegulatoryChangeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDefinitionController;
use App\Http\Controllers\ReportRunController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\ResidencyController;
use App\Http\Controllers\RiskAppetiteController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\RiskTreatmentController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SoaController;
use App\Http\Controllers\SodController;
use App\Http\Controllers\SpeakUpMetadataController;
use App\Http\Controllers\SpotCheckController;
use App\Http\Controllers\SsoConfigurationController;
use App\Http\Controllers\StrategyController;
use App\Http\Controllers\SubmissionPackController;
use App\Http\Controllers\SustainabilityController;
use App\Http\Controllers\TenantBrandingController;
use App\Http\Controllers\TenantSecurityController;
use App\Http\Controllers\TestInstanceController;
use App\Http\Controllers\TestScriptController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\Webhooks\SmsReceiptController;
use App\Http\Controllers\Webhooks\UssdController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use App\Http\Controllers\WhistleblowingController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// ── Operational health (Phase 16.3) — /up answers "alive", this answers
// "serving". No tenant data; production ingress restricts it to the
// monitoring network (docs/deployment).
Route::get('/health', HealthController::class)->name('health');

// ── PWA shell (Phase 7.10) — public by design
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');

// ── Whistleblowing intake (Phase 11.4) — public by design.
// Requiring a login to report wrongdoing defeats the control, so the intake
// and the reporter's one-way follow-up channel sit outside the auth group.
// Throttled, because an open endpoint is an open endpoint.
Route::middleware(['feature:whistleblowing', 'throttle:10,1'])->group(function () {
    Route::get('speak-up', [WhistleblowingController::class, 'create'])->name('whistleblowing.create');
    Route::post('speak-up', [WhistleblowingController::class, 'store'])->name('whistleblowing.store');
    Route::get('speak-up/submitted', [WhistleblowingController::class, 'submitted'])->name('whistleblowing.submitted');
    Route::get('speak-up/status', [WhistleblowingController::class, 'status'])->name('whistleblowing.status');
    Route::post('speak-up/status', [WhistleblowingController::class, 'checkStatus'])->name('whistleblowing.check');
    Route::post('speak-up/reply', [WhistleblowingController::class, 'reply'])->name('whistleblowing.reply');
});

// ── Exception Manager secure answer link (CR-01, R-H) — public by design.
// An external processor has no account; the hashed one-time token IS the
// credential. Scoped to a single escalation, expiring, revocable, and
// throttled hard because a token endpoint invites brute force.
Route::middleware(['feature:exception-manager', 'throttle:20,1'])->group(function () {
    Route::get('exception-response/{token}', [PublicExceptionResponseController::class, 'show'])
        ->name('public.exception-response.show');
    Route::post('exception-response/{token}', [PublicExceptionResponseController::class, 'submit'])
        ->name('public.exception-response.submit');
});

// ── Omnichannel webhooks (Phase 15.3/15.4) — public by design, each
// authenticated by its own mechanism: Meta signature, aggregator token,
// USSD gateway token. CSRF-exempt in bootstrap/app.php.
Route::prefix('webhooks')->group(function () {
    Route::get('whatsapp', [WhatsappWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
    Route::post('whatsapp', [WhatsappWebhookController::class, 'receive'])
        ->middleware('throttle:120,1')->name('webhooks.whatsapp');
    Route::post('sms/receipts', [SmsReceiptController::class, 'receive'])
        ->middleware('throttle:120,1')->name('webhooks.sms.receipts');
    // USSD attestation (15.4) — optional, behind its feature flag.
    Route::post('ussd', [UssdController::class, 'handle'])
        ->middleware(['feature:ussd', 'throttle:120,1'])->name('webhooks.ussd');
});

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

// ── Signed set-password flow — user invitations and admin resets ─────
// Public but unusable without a valid, unexpired signature; the controller
// also checks the password-hash fragment so a used link dies immediately.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('set-password/{user}', [SetPasswordController::class, 'show'])->name('invite.accept');
    Route::post('set-password/{user}', [SetPasswordController::class, 'store'])->name('invite.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Forced first-login password change (temporary-password accounts).
    Route::get('password/change', [ForcePasswordController::class, 'edit'])->name('password.force');
    Route::post('password/change', [ForcePasswordController::class, 'update'])->name('password.force.update');

    // ── Dashboard (all roles) ────────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Notifications ────────────────────────────────────────────────
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // ── Mobile, offline & omnichannel (Phase 15) ─────────────────────
    // The task view and offline endpoints carry no feature flag: a stale
    // cached device must always be able to sync its outbox back in.
    Route::get('my-tasks', [MobileTaskController::class, 'index'])->name('tasks.mine');
    Route::get('offline/bootstrap', [OfflineSyncController::class, 'bootstrap'])->name('offline.bootstrap');
    Route::post('offline/outbox', [OfflineSyncController::class, 'outbox'])->name('offline.outbox');
    Route::get('notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    Route::post('uploads/chunked', [ChunkedUploadController::class, 'store'])->name('uploads.chunked.store');
    Route::post('uploads/chunked/{uuid}/chunks', [ChunkedUploadController::class, 'appendChunk'])->name('uploads.chunked.append');
    Route::post('uploads/chunked/{uuid}/complete', [ChunkedUploadController::class, 'complete'])->name('uploads.chunked.complete');
    Route::delete('uploads/chunked/{uuid}', [ChunkedUploadController::class, 'destroy'])->name('uploads.chunked.abort');

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

    // ── Settings → Activity Log (CR3) ────────────────────────────────
    // A discrete permission, not implied by admin; exports carry their own
    // permission and are themselves logged.
    Route::middleware(['permission:view audit log', 'feature:audit-log-ui'])->group(function () {
        Route::get('settings/activity-log', [AuditLogController::class, 'index'])->name('settings.activity-log');
        Route::get('settings/activity-log/export', [AuditLogController::class, 'export'])
            ->middleware('permission:export audit log')->name('settings.activity-log.export');
        Route::get('settings/activity-log/export-pdf', [AuditLogController::class, 'exportPdf'])
            ->middleware('permission:export audit log')->name('settings.activity-log.export-pdf');
    });

    // ── Departmental control functions (CR-03) ───────────────────────
    // The checklist catalogue and the frequency engine. Reading it is
    // broad; editing a function is second line; IMPORTING the bank's
    // workbook is the administrator's, because one commit rewrites the
    // whole register in a single transaction. Gated exactly as
    // control-structure is, so the module can ship dark per tenant.
    Route::middleware('feature:control-functions')->group(function () {
        Route::middleware('permission:view control-functions')->group(function () {
            Route::get('control-functions', [ControlFunctionController::class, 'index'])->name('control-functions.index');
            Route::get('control-functions/compliance', [ControlFunctionController::class, 'compliance'])->name('control-functions.compliance');
            Route::get('control-functions/export', [ControlFunctionController::class, 'export'])
                ->middleware('permission:export reports')->name('control-functions.export');
            Route::get('control-functions/{control}', [ControlFunctionController::class, 'show'])->name('control-functions.show');
        });

        Route::middleware('permission:execute control-tasks')->group(function () {
            Route::post('control-functions/{control}/trigger', [ControlFunctionController::class, 'trigger'])->name('control-functions.trigger');
        });

        Route::middleware('permission:import control-functions')->group(function () {
            Route::get('control-function-imports', [ControlFunctionImportController::class, 'index'])->name('control-functions.import.index');
            Route::post('control-function-imports', [ControlFunctionImportController::class, 'store'])->name('control-functions.import.store');
            Route::post('control-function-imports/{controlFunctionImport}/commit', [ControlFunctionImportController::class, 'commit'])->name('control-functions.import.commit');
            Route::delete('control-function-imports/{controlFunctionImport}', [ControlFunctionImportController::class, 'discard'])->name('control-functions.import.discard');
        });
    });

    // ── Internal control structure (CR2-A) ───────────────────────────
    // The three sub-units and the control universe under them. Structure
    // admin ('manage control-structure') and control assignment ('attach
    // control-entities' / 'manage control-stakeholders') are separate
    // authorities — the route gates mirror that split.
    Route::middleware('feature:control-structure')->group(function () {
        Route::middleware('permission:view control-structure')->group(function () {
            Route::get('control-structure', [ControlStructureController::class, 'index'])->name('control-structure.index');
            Route::get('control-structure/units/{controlUnit}', [ControlStructureController::class, 'unit'])->name('control-structure.unit');
            Route::get('control-structure/entities/{controlEntity}', [ControlStructureController::class, 'entity'])->name('control-structure.entity');
        });

        Route::middleware('permission:manage control-structure')->group(function () {
            Route::post('control-structure/units', [ControlStructureController::class, 'storeUnit'])->name('control-structure.units.store');
            Route::put('control-structure/units/{controlUnit}', [ControlStructureController::class, 'updateUnit'])->name('control-structure.units.update');
            Route::post('control-structure/entities', [ControlStructureController::class, 'storeEntity'])->name('control-structure.entities.store');
            Route::put('control-structure/entities/{controlEntity}', [ControlStructureController::class, 'updateEntity'])->name('control-structure.entities.update');
            Route::delete('control-structure/entities/{controlEntity}', [ControlStructureController::class, 'deactivateEntity'])->name('control-structure.entities.deactivate');
        });

        Route::middleware('permission:attach control-entities')->group(function () {
            Route::post('control-structure/entities/{controlEntity}/controls', [ControlStructureController::class, 'attachControls'])->name('control-structure.entities.attach');
            Route::delete('control-structure/entities/{controlEntity}/controls/{control}', [ControlStructureController::class, 'detachControl'])->name('control-structure.entities.detach');
        });

        Route::middleware('permission:manage control-stakeholders')->group(function () {
            Route::post('controls/{control}/stakeholders', [ControlStructureController::class, 'addStakeholder'])->name('controls.stakeholders.store');
            Route::delete('controls/{control}/stakeholders/{stakeholder}', [ControlStructureController::class, 'removeStakeholder'])->name('controls.stakeholders.destroy');
        });
    });

    // ── Control library ──────────────────────────────────────────────
    // Templates before the resource so 'templates' isn't captured as a {control}.
    Route::middleware('role:System Administrator|Control Function Head|Control Officer')->group(function () {
        Route::get('controls/templates', [ControlController::class, 'templates'])->name('controls.templates');
        Route::post('controls/templates/{template}/adopt', [ControlController::class, 'adopt'])->name('controls.adopt');
    });

    // ── Control distribution (Phase 9.1/9.2) — before the resource so
    // 'distribution' isn't captured as a {control}.
    Route::middleware('feature:control-distribution')->group(function () {
        Route::get('controls/distribution', [ControlDistributionController::class, 'index'])->name('distributions.index');
        Route::get('distributions/{distribution}', [ControlDistributionController::class, 'show'])->name('distributions.show');
        Route::post('controls/{control}/distribute', [ControlDistributionController::class, 'store'])->name('controls.distribute');
        Route::post('distributions/{distribution}/acknowledge', [ControlDistributionController::class, 'acknowledge'])->name('distributions.acknowledge');
        Route::put('distributions/{distribution}/tasks/{task}', [ControlDistributionController::class, 'updateTask'])->name('distributions.tasks.update');
        Route::post('distributions/{distribution}/request-decline', [ControlDistributionController::class, 'requestDecline'])->name('distributions.request-decline');
        Route::post('distributions/{distribution}/decide-decline', [ControlDistributionController::class, 'decideDecline'])->name('distributions.decide-decline');
        Route::post('controls/{control}/propagation-preview', [ControlDistributionController::class, 'propagationPreview'])->name('controls.propagation-preview');
        Route::post('controls/{control}/propagate', [ControlDistributionController::class, 'propagate'])->name('controls.propagate');
    });

    Route::resource('controls', ControlController::class);
    Route::post('controls/{control}/submit', [ControlController::class, 'submit'])->name('controls.submit');
    Route::post('controls/{control}/approve', [ControlController::class, 'approve'])->name('controls.approve');
    Route::post('controls/{control}/reject', [ControlController::class, 'reject'])->name('controls.reject');
    Route::post('controls/{control}/retire', [ControlController::class, 'retire'])->name('controls.retire');
    Route::post('controls/{control}/assess', [ControlController::class, 'assess'])->name('controls.assess');

    // ── Risk register & mapping ──────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head|Control Officer|Executive Viewer|Line Manager')->group(function () {
        // Static segments first so they are not captured as a {risk}.
        Route::get('risks/gaps', [RiskController::class, 'gaps'])->name('risks.gaps');

        Route::middleware('feature:risk-heatmap')->group(function () {
            Route::get('risks/heatmap', [RiskController::class, 'heatmap'])->name('risks.heatmap');
            Route::get('risks/heatmap/cell', [RiskController::class, 'heatmapCell'])->name('risks.heatmap.cell');
        });

        Route::resource('risks', RiskController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('risks/{risk}/controls', [RiskController::class, 'attachControl'])->name('risks.controls.attach');
        Route::delete('risks/{risk}/controls/{control}', [RiskController::class, 'detachControl'])->name('risks.controls.detach');

        // ── Risk assessments (Phase 10.1) ────────────────────────────
        Route::middleware('feature:risk-assessments')->group(function () {
            Route::post('risks/{risk}/assessments', [RiskAssessmentController::class, 'store'])
                ->name('risks.assessments.store');
            Route::post('risks/{risk}/assessments/{assessment}/publish', [RiskAssessmentController::class, 'publish'])
                ->name('risks.assessments.publish');
            Route::post('risks/{risk}/assessments/{assessment}/reject', [RiskAssessmentController::class, 'reject'])
                ->name('risks.assessments.reject');
            Route::post('risks/{risk}/assessments/{assessment}/simulate', [RiskAssessmentController::class, 'simulate'])
                ->name('risks.assessments.simulate');
        });

        // ── Risk treatments (Phase 10.4) ─────────────────────────────
        Route::middleware('feature:risk-treatments')->group(function () {
            Route::get('treatments', [RiskTreatmentController::class, 'index'])->name('treatments.index');
            Route::get('treatments/{treatment}', [RiskTreatmentController::class, 'show'])->name('treatments.show');
            Route::post('risks/{risk}/treatments', [RiskTreatmentController::class, 'store'])->name('risks.treatments.store');
            Route::post('treatments/{treatment}/approve', [RiskTreatmentController::class, 'approve'])->name('treatments.approve');
            Route::post('treatments/{treatment}/progress', [RiskTreatmentController::class, 'progress'])->name('treatments.progress');
            Route::post('treatments/{treatment}/verify', [RiskTreatmentController::class, 'verify'])->name('treatments.verify');
            Route::post('treatments/{treatment}/cancel', [RiskTreatmentController::class, 'cancel'])->name('treatments.cancel');
            Route::post('treatments/{treatment}/milestones', [RiskTreatmentController::class, 'storeMilestone'])
                ->name('treatments.milestones.store');
            Route::post('treatments/{treatment}/milestones/{milestone}/complete', [RiskTreatmentController::class, 'completeMilestone'])
                ->name('treatments.milestones.complete');
        });
    });

    // ── Risk appetite (Phase 10.3) ───────────────────────────────────
    Route::middleware(['feature:risk-appetite', 'permission:view appetite'])->group(function () {
        Route::get('risk-appetite', [RiskAppetiteController::class, 'index'])->name('appetite.index');
        Route::post('risk-appetite', [RiskAppetiteController::class, 'store'])->name('appetite.store');
        Route::post('risk-appetite/{appetite}/submit', [RiskAppetiteController::class, 'submit'])->name('appetite.submit');
        Route::post('risk-appetite/{appetite}/approve', [RiskAppetiteController::class, 'approve'])->name('appetite.approve');
        Route::post('risk-appetite/{appetite}/supersede', [RiskAppetiteController::class, 'supersede'])->name('appetite.supersede');
    });

    // ── KRI / KPI engine (Phase 10.5) ────────────────────────────────
    Route::middleware(['feature:metrics', 'permission:view metrics'])->group(function () {
        Route::get('metrics', [MetricController::class, 'index'])->name('metrics.index');
        Route::post('metrics', [MetricController::class, 'store'])->name('metrics.store');
        Route::get('metrics/{metric}', [MetricController::class, 'show'])->name('metrics.show');
        Route::put('metrics/{metric}', [MetricController::class, 'update'])->name('metrics.update');
        Route::post('metrics/{metric}/values', [MetricController::class, 'capture'])->name('metrics.capture');
        Route::post('metrics/{metric}/breaches/{breach}/acknowledge', [MetricController::class, 'acknowledgeBreach'])
            ->name('metrics.breaches.acknowledge');
        Route::post('metrics/{metric}/breaches/{breach}/resolve', [MetricController::class, 'resolveBreach'])
            ->name('metrics.breaches.resolve');
    });

    // ── Strategy & performance alignment (Phase 17.1) ────────────────
    // The map and the scorecard are the board's two views of the same
    // register; both read the roll-up rather than storing a second copy
    // of it.
    Route::middleware(['feature:objectives', 'permission:view objectives'])->group(function () {
        Route::get('strategy/map', [StrategyController::class, 'map'])->name('strategy.map');
        Route::get('strategy/scorecard', [StrategyController::class, 'scorecard'])->name('strategy.scorecard');

        Route::get('objectives', [ObjectiveController::class, 'index'])->name('objectives.index');
        Route::post('objectives', [ObjectiveController::class, 'store'])->name('objectives.store');
        Route::get('objectives/{objective}', [ObjectiveController::class, 'show'])->name('objectives.show');
        Route::put('objectives/{objective}', [ObjectiveController::class, 'update'])->name('objectives.update');
        Route::post('objectives/{objective}/approve', [ObjectiveController::class, 'approve'])->name('objectives.approve');
        Route::post('objectives/{objective}/progress', [ObjectiveController::class, 'progress'])->name('objectives.progress');
        Route::post('objectives/{objective}/recalculate', [ObjectiveController::class, 'recalculate'])
            ->name('objectives.recalculate');
        Route::post('objectives/{objective}/measures', [ObjectiveController::class, 'storeMeasure'])
            ->name('objectives.measures.store');
        Route::delete('objectives/{objective}/measures/{measure}', [ObjectiveController::class, 'destroyMeasure'])
            ->name('objectives.measures.destroy');

        Route::post('initiatives', [InitiativeController::class, 'store'])->name('initiatives.store');
        Route::put('initiatives/{initiative}', [InitiativeController::class, 'update'])->name('initiatives.update');
        Route::post('initiatives/{initiative}/milestones/{milestone}', [InitiativeController::class, 'completeMilestone'])
            ->name('initiatives.milestones.update');
    });

    // ── Third-party / vendor risk (Phase 17.2) ───────────────────────
    // Approving a due-diligence conclusion, filing a material-outsourcing
    // notification and registering a contract each carry their own
    // permission inside the group: they are different authorities held by
    // different people in most institutions.
    Route::middleware(['feature:vendors', 'permission:view vendors'])->group(function () {
        Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::get('vendors/concentration', [VendorController::class, 'concentration'])->name('vendors.concentration');
        Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
        Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');

        Route::post('vendors/{vendor}/assessments', [VendorController::class, 'storeAssessment'])
            ->name('vendors.assessments.store');
        Route::post('vendors/{vendor}/assessments/{assessment}/submit', [VendorController::class, 'submitAssessment'])
            ->name('vendors.assessments.submit');
        Route::post('vendors/{vendor}/assessments/{assessment}/approve', [VendorController::class, 'approveAssessment'])
            ->name('vendors.assessments.approve');
        Route::post('vendors/{vendor}/assessments/{assessment}/reject', [VendorController::class, 'rejectAssessment'])
            ->name('vendors.assessments.reject');

        Route::post('vendors/{vendor}/findings', [VendorController::class, 'storeFinding'])
            ->name('vendors.findings.store');
        Route::put('vendors/{vendor}/findings/{finding}', [VendorController::class, 'updateFinding'])
            ->name('vendors.findings.update');

        Route::post('vendors/{vendor}/screenings', [VendorController::class, 'storeScreening'])
            ->name('vendors.screenings.store');
        Route::post('vendors/{vendor}/screenings/{screening}/disposition', [VendorController::class, 'dispositionScreening'])
            ->name('vendors.screenings.disposition');

        Route::post('vendors/{vendor}/notification', [VendorController::class, 'recordNotification'])
            ->name('vendors.notification.store');

        Route::post('vendors/{vendor}/contracts', [VendorController::class, 'storeContract'])
            ->name('vendors.contracts.store');
        Route::put('vendors/{vendor}/contracts/{contract}', [VendorController::class, 'updateContract'])
            ->name('vendors.contracts.update');
    });

    // ── IFRS S1/S2 sustainability controls (Phase 17.3) ──────────────
    // Preparing a figure and verifying it are separate permissions on
    // purpose: a control over sustainability reporting that one person
    // both operates and signs off is not a control (COSO ICSR).
    Route::middleware(['feature:sustainability', 'permission:view sustainability'])->group(function () {
        Route::get('sustainability', [SustainabilityController::class, 'index'])->name('sustainability.index');
        Route::post('sustainability/materiality', [SustainabilityController::class, 'storeMateriality'])
            ->name('sustainability.materiality.store');
        Route::post('sustainability/materiality/{assessment}/approve', [SustainabilityController::class, 'approveMateriality'])
            ->name('sustainability.materiality.approve');

        Route::get('sustainability/ghg', [SustainabilityController::class, 'ghg'])->name('sustainability.ghg');
        Route::post('sustainability/ghg', [SustainabilityController::class, 'storeDataPoint'])
            ->name('sustainability.ghg.store');
        Route::put('sustainability/ghg/{point}', [SustainabilityController::class, 'updateDataPoint'])
            ->name('sustainability.ghg.update');
        Route::post('sustainability/ghg/{point}/verify', [SustainabilityController::class, 'verifyDataPoint'])
            ->name('sustainability.ghg.verify');
        Route::post('sustainability/ghg/{point}/defer', [SustainabilityController::class, 'deferDataPoint'])
            ->name('sustainability.ghg.defer');

        Route::get('sustainability/filings', [SustainabilityController::class, 'filings'])
            ->name('sustainability.filings');
        Route::post('sustainability/filings', [SustainabilityController::class, 'storeFiling'])
            ->name('sustainability.filings.store');
        Route::post('sustainability/filings/{filing}/verify', [SustainabilityController::class, 'verifyFiling'])
            ->name('sustainability.filings.verify');
        Route::post('sustainability/filings/{filing}/stages/{stage}', [SustainabilityController::class, 'submitStage'])
            ->name('sustainability.filings.stages.submit');
    });

    // ── Combined assurance map (Phase 17.4) ──────────────────────────
    // Recording a reliance decision carries its own permission: a combined
    // assurance report is signed on the strength of exactly those
    // decisions, so they are a named authority rather than general
    // maintenance of the map.
    Route::middleware(['feature:assurance', 'permission:view assurance'])->group(function () {
        Route::get('assurance', [AssuranceController::class, 'map'])->name('assurance.map');
        Route::post('assurance/providers', [AssuranceController::class, 'storeProvider'])
            ->name('assurance.providers.store');
        Route::post('assurance/activities', [AssuranceController::class, 'storeActivity'])
            ->name('assurance.activities.store');
        Route::post('assurance/activities/{activity}/reliance', [AssuranceController::class, 'recordReliance'])
            ->name('assurance.activities.reliance');
    });

    // ── Linkage graph (Phase 10.6) ───────────────────────────────────
    Route::middleware('feature:linkage')->group(function () {
        Route::post('links', [LinkageController::class, 'store'])->name('links.store');
        Route::delete('links/{link}', [LinkageController::class, 'destroy'])->name('links.destroy');
        Route::get('links/candidates', [LinkageController::class, 'candidates'])->name('links.candidates');
        Route::get('links/graph/{type}/{id}', [LinkageController::class, 'graph'])->name('links.graph');
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

    // ── Exception Manager (CR-01): departmental escalation, response &
    // closure. Rows are escalations, not exceptions — the addressed loop.
    Route::middleware('feature:exception-manager')->group(function () {
        Route::get('exception-manager', [ExceptionManagerController::class, 'index'])
            ->name('exception-manager.index');

        Route::middleware('permission:escalate exceptions')->group(function () {
            Route::post('exceptions/{exception}/escalations', [ExceptionManagerController::class, 'issue'])
                ->name('exception-manager.issue');
            Route::post('exception-manager/{escalation}/links', [ExceptionManagerController::class, 'createLink'])
                ->name('exception-manager.links.store');
            Route::post('exception-manager/{escalation}/links/{link}/revoke', [ExceptionManagerController::class, 'revokeLink'])
                ->name('exception-manager.links.revoke');
        });

        Route::middleware('permission:respond exceptions')->group(function () {
            Route::get('exception-manager/{escalation}/respond', [ExceptionResponseController::class, 'respond'])
                ->name('exception-manager.respond');
            Route::post('exception-manager/{escalation}/respond/draft', [ExceptionResponseController::class, 'saveDraft'])
                ->name('exception-manager.respond.draft');
            Route::post('exception-manager/{escalation}/respond', [ExceptionResponseController::class, 'submit'])
                ->name('exception-manager.respond.submit');
        });

        Route::middleware('permission:review exception-responses')
            ->post('exception-manager/{escalation}/responses/{response}/review', [ExceptionManagerController::class, 'review'])
            ->name('exception-manager.review');

        Route::post('exception-manager/{escalation}/close', [ExceptionManagerController::class, 'close'])
            ->name('exception-manager.close');

        Route::middleware('permission:withdraw exceptions')
            ->post('exception-manager/{escalation}/withdraw', [ExceptionManagerController::class, 'withdraw'])
            ->name('exception-manager.withdraw');

        // The department's committed actions (CR1.5).
        Route::post('exception-actions/{action}/progress', [ExceptionResponseController::class, 'progressAction'])
            ->name('exception-actions.progress');
        Route::post('exception-actions/{action}/complete', [ExceptionResponseController::class, 'completeAction'])
            ->name('exception-actions.complete');

        // Dynamic segment last, so it never captures the literals above.
        Route::get('exception-manager/{escalation}', [ExceptionManagerController::class, 'show'])
            ->name('exception-manager.show');
    });

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
        Route::get('exception-manager.pdf', [ReportController::class, 'exceptionManagerPdf'])->name('reports.exception-manager.pdf');
        Route::get('exception-manager.xlsx', [ReportController::class, 'exceptionManagerXlsx'])->name('reports.exception-manager.xlsx');
        Route::get('controls.xlsx', [ReportController::class, 'controlsXlsx'])->name('reports.controls.xlsx');
        Route::get('testing-summary.pdf', [ReportController::class, 'testingSummaryPdf'])->name('reports.testing-summary');
        Route::get('test-instances.xlsx', [ReportController::class, 'testInstancesXlsx'])->name('reports.test-instances.xlsx');
        Route::get('board-pack', [ReportController::class, 'boardPack'])->name('reports.board-pack');
    });

    // ── Configurable dashboards (Phase 13.2) ─────────────────────────
    Route::middleware('feature:dashboard-builder')->group(function () {
        Route::middleware('permission:view dashboards')
            ->get('dashboards', [DashboardController::class, 'library'])->name('dashboards.index');

        // Static segments first so they are not captured as {dashboard}.
        Route::middleware('permission:manage dashboards')->group(function () {
            Route::get('dashboards/new', [DashboardBuilderController::class, 'create'])->name('dashboards.create');
            Route::post('dashboards', [DashboardBuilderController::class, 'store'])->name('dashboards.store');
        });

        Route::middleware('permission:view dashboards')
            ->get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');

        Route::middleware('permission:manage dashboards')->group(function () {
            Route::get('dashboards/{dashboard}/edit', [DashboardBuilderController::class, 'edit'])->name('dashboards.edit');
            Route::put('dashboards/{dashboard}', [DashboardBuilderController::class, 'update'])->name('dashboards.update');
            Route::delete('dashboards/{dashboard}', [DashboardBuilderController::class, 'destroy'])->name('dashboards.destroy');
            Route::post('dashboards/{dashboard}/duplicate', [DashboardBuilderController::class, 'duplicate'])->name('dashboards.duplicate');
            Route::put('dashboards/{dashboard}/layout', [DashboardBuilderController::class, 'layout'])->name('dashboards.layout');
            Route::post('dashboards/{dashboard}/widgets', [DashboardBuilderController::class, 'storeWidget'])->name('dashboards.widgets.store');
            Route::put('dashboards/{dashboard}/widgets/{widget}', [DashboardBuilderController::class, 'updateWidget'])->name('dashboards.widgets.update');
            Route::delete('dashboards/{dashboard}/widgets/{widget}', [DashboardBuilderController::class, 'destroyWidget'])->name('dashboards.widgets.destroy');
        });
    });

    // ── Report designer, schedules & runs (Phase 13.3) ───────────────
    Route::middleware('feature:report-designer')->group(function () {
        Route::middleware('permission:export reports')->group(function () {
            Route::get('report-library', [ReportDefinitionController::class, 'index'])->name('reports.library');
            Route::get('report-library/{definition}', [ReportDefinitionController::class, 'show'])->name('reports.definitions.show');
            Route::post('report-library/{definition}/run', [ReportRunController::class, 'store'])->name('reports.definitions.run');
            Route::get('report-runs', [ReportRunController::class, 'index'])->name('reports.runs');
            Route::get('report-runs/{run}/download', [ReportRunController::class, 'download'])->name('reports.runs.download');
        });

        Route::middleware('permission:design reports')->group(function () {
            Route::get('report-designer/new', [ReportDefinitionController::class, 'create'])->name('reports.definitions.create');
            Route::post('report-designer', [ReportDefinitionController::class, 'store'])->name('reports.definitions.store');
            Route::get('report-designer/{definition}', [ReportDefinitionController::class, 'edit'])->name('reports.definitions.edit');
            Route::put('report-designer/{definition}', [ReportDefinitionController::class, 'update'])->name('reports.definitions.update');
            Route::delete('report-designer/{definition}', [ReportDefinitionController::class, 'destroy'])->name('reports.definitions.destroy');
        });

        Route::middleware('permission:approve report-definitions')
            ->post('report-designer/{definition}/approve', [ReportDefinitionController::class, 'approve'])
            ->name('reports.definitions.approve');

        Route::middleware('permission:schedule reports')->group(function () {
            Route::get('report-schedules', [ReportScheduleController::class, 'index'])->name('reports.schedules');
            Route::post('report-schedules', [ReportScheduleController::class, 'store'])->name('reports.schedules.store');
            Route::put('report-schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('reports.schedules.update');
            Route::delete('report-schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('reports.schedules.destroy');
            Route::post('report-schedules/{schedule}/run-now', [ReportScheduleController::class, 'runNow'])->name('reports.schedules.run-now');
        });
    });

    // ── Regulatory submission packs (Phase 13.4) ─────────────────────
    Route::middleware('feature:submission-packs')->group(function () {
        Route::middleware('permission:view submissions')->group(function () {
            Route::get('submissions', [SubmissionPackController::class, 'index'])->name('submissions.index');
            Route::get('submissions/{pack}', [SubmissionPackController::class, 'show'])->name('submissions.show');
            Route::get('submissions/{pack}/document', [SubmissionPackController::class, 'download'])->name('submissions.download');
        });

        Route::middleware('permission:generate submissions')->group(function () {
            Route::post('submissions', [SubmissionPackController::class, 'store'])->name('submissions.store');
            Route::put('submissions/{pack}', [SubmissionPackController::class, 'update'])->name('submissions.update');
            Route::post('submissions/{pack}/regenerate', [SubmissionPackController::class, 'regenerate'])->name('submissions.regenerate');
            Route::post('submissions/{pack}/send-for-review', [SubmissionPackController::class, 'sendForReview'])->name('submissions.send-for-review');
        });

        Route::middleware('permission:review submissions')
            ->post('submissions/{pack}/review', [SubmissionPackController::class, 'review'])->name('submissions.review');

        Route::middleware('permission:approve submissions')
            ->post('submissions/{pack}/approve', [SubmissionPackController::class, 'approve'])->name('submissions.approve');

        Route::middleware('permission:file submissions')->group(function () {
            Route::post('submissions/{pack}/file', [SubmissionPackController::class, 'file'])->name('submissions.file');
            Route::post('submissions/{pack}/acknowledge', [SubmissionPackController::class, 'acknowledge'])->name('submissions.acknowledge');
            Route::post('submissions/{pack}/reject', [SubmissionPackController::class, 'reject'])->name('submissions.reject');
        });
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

        // ── Statement of Applicability (Phase 16.4) ──────────────────
        Route::get('frameworks/{framework}/soa', [SoaController::class, 'show'])->name('frameworks.soa');
        Route::put('frameworks/{framework}/soa', [SoaController::class, 'decide'])
            ->middleware('permission:manage soa')->name('frameworks.soa.decide');
        Route::get('frameworks/{framework}/soa/export', [SoaController::class, 'export'])->name('frameworks.soa.export');
    });

    // ── Group, entities & consolidation (Phase 16.1) ─────────────────
    Route::middleware('feature:entities')->group(function () {
        Route::get('entities', [EntityController::class, 'index'])
            ->middleware('permission:view entities')->name('entities.index');
        Route::post('entities', [EntityController::class, 'store'])
            ->middleware('permission:manage entities')->name('entities.store');
        Route::put('entities/{entity}', [EntityController::class, 'update'])
            ->middleware('permission:manage entities')->name('entities.update');
        Route::post('entities/{entity}/grants', [EntityController::class, 'grantAccess'])
            ->middleware('permission:grant entity-access')->name('entities.grants.store');
        Route::delete('entities/{entity}/grants/{user}', [EntityController::class, 'revokeAccess'])
            ->middleware('permission:grant entity-access')->name('entities.grants.destroy');

        Route::get('group', [GroupDashboardController::class, 'index'])
            ->middleware('permission:view group-dashboard')->name('group.dashboard');
    });

    // ── Data residency (Phase 16.2) — outside the admin area because the
    // second line records transfers day to day; the declaration itself is
    // read-only everywhere (changing it is a re-provisioning event).
    Route::middleware(['feature:residency', 'permission:view residency'])->group(function () {
        Route::get('residency', [ResidencyController::class, 'index'])->name('residency.index');
        Route::post('residency/transfers', [ResidencyController::class, 'storeTransfer'])
            ->middleware('permission:record transfers')->name('residency.transfers.store');
        Route::post('residency/transfers/{transfer}/authorise', [ResidencyController::class, 'authoriseTransfer'])
            ->middleware('permission:authorise transfers')->name('residency.transfers.authorise');
        Route::post('residency/transfers/{transfer}/complete', [ResidencyController::class, 'completeTransfer'])
            ->middleware('permission:record transfers')->name('residency.transfers.complete');
        Route::post('residency/attestations', [ResidencyController::class, 'storeAttestation'])
            ->middleware(['permission:manage residency', 'throttle:10,1'])->name('residency.attestations.store');
        Route::get('residency/attestations/{attestation}/download', [ResidencyController::class, 'downloadAttestation'])
            ->name('residency.attestations.download');
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

    // ── CSA, surveys & attestation campaigns (Phase 9.3–9.5) ─────────
    Route::middleware('feature:csa')->group(function () {
        Route::get('csa/my-responses', [CsaResponseController::class, 'index'])->name('csa-responses.mine');
        Route::resource('csa-campaigns', CsaCampaignController::class)->only(['index', 'create', 'store', 'show']);
        Route::put('csa-campaigns/{csa_campaign}/questions', [CsaCampaignController::class, 'syncQuestions'])->name('csa-campaigns.questions');
        Route::post('csa-campaigns/{csa_campaign}/approve', [CsaCampaignController::class, 'approve'])->name('csa-campaigns.approve');
        Route::post('csa-campaigns/{csa_campaign}/open', [CsaCampaignController::class, 'open'])->name('csa-campaigns.open');
        Route::post('csa-campaigns/{csa_campaign}/close', [CsaCampaignController::class, 'close'])->name('csa-campaigns.close');

        Route::get('csa-responses/{response}', [CsaResponseController::class, 'show'])->name('csa-responses.show');
        Route::post('csa-responses/{response}/answers', [CsaResponseController::class, 'save'])->name('csa-responses.answers');
        Route::post('csa-responses/{response}/review', [CsaResponseController::class, 'review'])->name('csa-responses.review');
        Route::post('csa-responses/{response}/approve-rating', [CsaResponseController::class, 'approveRating'])->name('csa-responses.approve-rating');
    });

    Route::middleware('feature:surveys')
        ->post('surveys/{campaign}/respond-anonymously', [CsaResponseController::class, 'submitAnonymous'])
        ->name('surveys.respond-anonymously');

    // ── Improvement database (Phase 9.9)
    Route::middleware('feature:improvements')->group(function () {
        Route::get('improvements', [ImprovementActionController::class, 'index'])->name('improvements.index');
        Route::post('improvements', [ImprovementActionController::class, 'store'])->name('improvements.store');
        Route::post('improvements/{improvement}/decide', [ImprovementActionController::class, 'decide'])->name('improvements.decide');
        Route::post('improvements/{improvement}/progress', [ImprovementActionController::class, 'progress'])->name('improvements.progress');
        Route::post('improvements/{improvement}/verify', [ImprovementActionController::class, 'verify'])->name('improvements.verify');
    });

    // ── Document management (Phase 9.6)
    Route::middleware('feature:documents')->group(function () {
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('document-folders', [DocumentController::class, 'storeFolder'])->name('document-folders.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::post('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::post('documents/{document}/submit', [DocumentController::class, 'submit'])->name('documents.submit');
        Route::post('documents/{document}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
        Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
        Route::post('documents/{document}/publish', [DocumentController::class, 'publish'])->name('documents.publish');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    });

    // ── Attestations (Phase 9.5)
    Route::middleware('feature:attestations')->group(function () {
        Route::get('attestations', [AttestationController::class, 'index'])->name('attestations.index');
        Route::post('attestations/{campaign}', [AttestationController::class, 'store'])->name('attestations.store');
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

    // ── Policy management (Phase 11.1) ───────────────────────────────
    Route::middleware(['feature:policies', 'permission:view policies'])->group(function () {
        // Static segments first so they are not captured as a {policy}.
        Route::get('policies/gaps', [PolicyController::class, 'gaps'])->name('policies.gaps');
        Route::get('policy-exceptions', [PolicyExceptionController::class, 'index'])->name('policy-exceptions.index');

        Route::resource('policies', PolicyController::class)->only(['index', 'create', 'store', 'show', 'update']);
        Route::post('policies/{policy}/submit', [PolicyController::class, 'submit'])->name('policies.submit');
        Route::post('policies/{policy}/approve', [PolicyController::class, 'approve'])->name('policies.approve');
        Route::post('policies/{policy}/reject', [PolicyController::class, 'reject'])->name('policies.reject');
        Route::post('policies/{policy}/publish', [PolicyController::class, 'publish'])->name('policies.publish');
        Route::post('policies/{policy}/revise', [PolicyController::class, 'revise'])->name('policies.revise');
        Route::post('policies/{policy}/withdraw', [PolicyController::class, 'withdraw'])->name('policies.withdraw');

        Route::post('policies/{policy}/exceptions', [PolicyExceptionController::class, 'store'])
            ->name('policies.exceptions.store');
        Route::post('policy-exceptions/{exception}/decide', [PolicyExceptionController::class, 'decide'])
            ->name('policy-exceptions.decide');
        Route::post('policy-exceptions/{exception}/revoke', [PolicyExceptionController::class, 'revoke'])
            ->name('policy-exceptions.revoke');
    });

    // ── Incident management (Phase 11.2) ─────────────────────────────
    Route::middleware(['feature:incidents', 'permission:view incidents'])->group(function () {
        Route::resource('incidents', IncidentController::class)->only(['index', 'create', 'store', 'show', 'update']);
        Route::post('incidents/{incident}/triage', [IncidentController::class, 'triage'])->name('incidents.triage');
        Route::post('incidents/{incident}/progress', [IncidentController::class, 'progress'])->name('incidents.progress');
        Route::post('incidents/{incident}/loss', [IncidentController::class, 'recordLoss'])->name('incidents.loss');
        Route::post('incidents/{incident}/close', [IncidentController::class, 'close'])->name('incidents.close');
        Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])->name('incidents.reopen');
        Route::post('incidents/{incident}/actions', [IncidentController::class, 'storeAction'])
            ->name('incidents.actions.store');
        Route::put('incidents/{incident}/actions/{action}', [IncidentController::class, 'updateAction'])
            ->name('incidents.actions.update');
        Route::post('incidents/{incident}/controls', [IncidentController::class, 'linkControls'])
            ->name('incidents.controls.link');
        Route::post('incidents/{incident}/notifications/refresh', [IncidentController::class, 'refreshNotifications'])
            ->name('incidents.notifications.refresh');
        Route::post('incidents/{incident}/notifications/{notification}', [IncidentController::class, 'recordNotification'])
            ->name('incidents.notifications.record');
        Route::post('incidents/{incident}/notifications/{notification}/waive', [IncidentController::class, 'waiveNotification'])
            ->name('incidents.notifications.waive');
    });

    // ── Consumer complaints (Phase 11.3) ─────────────────────────────
    Route::middleware(['feature:complaints', 'permission:view complaints'])->group(function () {
        Route::get('complaints/analysis', [ComplaintController::class, 'analysis'])->name('complaints.analysis');
        Route::get('complaints/cpd-return.xlsx', [ComplaintController::class, 'cpdReturn'])->name('complaints.cpd-return');

        Route::resource('complaints', ComplaintController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('complaints/{complaint}/acknowledge', [ComplaintController::class, 'acknowledge'])
            ->name('complaints.acknowledge');
        Route::post('complaints/{complaint}/assign', [ComplaintController::class, 'assign'])->name('complaints.assign');
        Route::post('complaints/{complaint}/progress', [ComplaintController::class, 'progress'])->name('complaints.progress');
        Route::post('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve'])->name('complaints.resolve');
        Route::post('complaints/{complaint}/close', [ComplaintController::class, 'close'])->name('complaints.close');
        Route::post('complaints/{complaint}/reopen', [ComplaintController::class, 'reopen'])->name('complaints.reopen');
        Route::post('complaints/{complaint}/escalate', [ComplaintController::class, 'escalate'])->name('complaints.escalate');
        Route::post('complaints/{complaint}/incident', [ComplaintController::class, 'linkIncident'])
            ->name('complaints.link-incident');
    });

    // ── Cases & investigations (Phase 11.4) ──────────────────────────
    // Route middleware is the coarse gate only: every case is additionally
    // filtered by the allowlist scope and InvestigationCasePolicy, with no
    // administrator bypass.
    Route::middleware(['feature:cases', 'permission:view cases'])->group(function () {
        Route::get('cases/board-extract', [CaseController::class, 'boardExtract'])->name('cases.board-extract');

        Route::resource('cases', CaseController::class)->only(['index', 'store', 'show'])
            ->parameters(['cases' => 'case']);
        Route::post('cases/{case}/assess', [CaseController::class, 'assess'])->name('cases.assess');
        Route::post('cases/{case}/investigate', [CaseController::class, 'investigate'])->name('cases.investigate');
        Route::post('cases/{case}/conclude', [CaseController::class, 'conclude'])->name('cases.conclude');
        Route::post('cases/{case}/close', [CaseController::class, 'close'])->name('cases.close');
        Route::post('cases/{case}/notes', [CaseController::class, 'storeNote'])->name('cases.notes.store');
        Route::post('cases/{case}/access', [CaseController::class, 'grantAccess'])->name('cases.access.grant');
        Route::delete('cases/{case}/access', [CaseController::class, 'revokeAccess'])->name('cases.access.revoke');

        // ── Reporter technical metadata (CR) ─────────────────────────
        // The permission middleware here is the coarse gate; the
        // controller re-checks case membership, the tier permissions and
        // — for Tier 2 — the approved break-glass reveal, and every
        // reveal render writes the immutable Metadata Access Log.
        Route::middleware('permission:speak_up.metadata.view_basic')
            ->get('cases/{case}/metadata', [SpeakUpMetadataController::class, 'show'])
            ->name('cases.metadata.show');
        Route::middleware('permission:speak_up.metadata.request_reveal')
            ->post('cases/{case}/metadata/reveal-requests', [SpeakUpMetadataController::class, 'requestReveal'])
            ->name('cases.metadata.reveal.request');
        Route::middleware('permission:speak_up.metadata.approve_reveal')->group(function () {
            Route::post('cases/{case}/legal-hold', [SpeakUpMetadataController::class, 'setLegalHold'])
                ->name('cases.legal-hold.set');
            Route::delete('cases/{case}/legal-hold', [SpeakUpMetadataController::class, 'liftLegalHold'])
                ->name('cases.legal-hold.lift');
        });
    });

    // ── Speak Up reveal approvals (CR) ───────────────────────────────
    // Outside the 'view cases' gate on purpose: deciding a reveal is an
    // authority over the metadata vault, not a seat on the investigation,
    // so a standalone Reveal Approver (a DPO, legal counsel) works from
    // this queue without holding any case permission. Routes key on the
    // reveal request, whose tenant scope — not the case allowlist —
    // resolves the binding.
    Route::middleware(['feature:cases', 'permission:speak_up.metadata.approve_reveal'])->group(function () {
        Route::get('speak-up/reveal-requests', [SpeakUpMetadataController::class, 'revealQueue'])
            ->name('speak-up.reveal-requests');
        Route::post('speak-up/reveal-requests/{revealRequest}/decide', [SpeakUpMetadataController::class, 'decideReveal'])
            ->name('speak-up.reveal-requests.decide');
    });

    // ── Continuous controls monitoring (Phase 12.3–12.6) ─────────────
    // Route middleware is the coarse gate; MonitoringRulePolicy and
    // MonitoringService both re-check the approval separation, so an
    // administrator cannot approve their own rule from any surface.
    Route::middleware(['feature:continuous-monitoring', 'permission:view monitoring'])->group(function () {
        Route::get('monitoring', MonitoringDashboardController::class)->name('monitoring.dashboard');

        Route::get('monitoring/rules', [MonitoringRuleController::class, 'index'])->name('monitoring-rules.index');
        Route::post('monitoring/rules', [MonitoringRuleController::class, 'store'])->name('monitoring-rules.store');
        Route::get('monitoring/rules/{rule}', [MonitoringRuleController::class, 'show'])->name('monitoring-rules.show');
        Route::put('monitoring/rules/{rule}', [MonitoringRuleController::class, 'update'])->name('monitoring-rules.update');
        Route::post('monitoring/rules/{rule}/submit', [MonitoringRuleController::class, 'submit'])->name('monitoring-rules.submit');
        Route::post('monitoring/rules/{rule}/approve', [MonitoringRuleController::class, 'approve'])->name('monitoring-rules.approve');
        Route::post('monitoring/rules/{rule}/reject', [MonitoringRuleController::class, 'reject'])->name('monitoring-rules.reject');
        Route::post('monitoring/rules/{rule}/pause', [MonitoringRuleController::class, 'pause'])->name('monitoring-rules.pause');
        Route::post('monitoring/rules/{rule}/retire', [MonitoringRuleController::class, 'retire'])->name('monitoring-rules.retire');
        // Running by hand reaches a live source, so it is throttled.
        Route::post('monitoring/rules/{rule}/run', [MonitoringRuleController::class, 'run'])
            ->middleware('throttle:20,1')->name('monitoring-rules.run');

        Route::get('monitoring/runs', [MonitoringRunController::class, 'index'])->name('monitoring-runs.index');
        Route::get('monitoring/runs/{run}', [MonitoringRunController::class, 'show'])->name('monitoring-runs.show');

        Route::get('monitoring/findings', [MonitoringFindingController::class, 'index'])->name('monitoring-findings.index');
        Route::post('monitoring/findings/bulk-review', [MonitoringFindingController::class, 'bulkReview'])
            ->name('monitoring-findings.bulk-review');
        Route::post('monitoring/findings/{finding}/review', [MonitoringFindingController::class, 'review'])
            ->name('monitoring-findings.review');
    });

    // ── Segregation-of-duties analysis (Phase 12.3) ──────────────────
    Route::middleware(['feature:sod-analysis', 'permission:view sod'])->group(function () {
        Route::get('sod/conflicts', [SodController::class, 'conflicts'])->name('sod.conflicts');
        Route::post('sod/conflicts', [SodController::class, 'storeConflict'])->name('sod.conflicts.store');
        Route::put('sod/conflicts/{rule}', [SodController::class, 'updateConflict'])->name('sod.conflicts.update');
        Route::delete('sod/conflicts/{rule}', [SodController::class, 'destroyConflict'])->name('sod.conflicts.destroy');

        Route::get('sod/violations', [SodController::class, 'violations'])->name('sod.violations');
        Route::post('sod/violations/{violation}/mitigate', [SodController::class, 'mitigate'])->name('sod.violations.mitigate');
        Route::post('sod/violations/{violation}/accept', [SodController::class, 'accept'])->name('sod.violations.accept');
        Route::post('sod/violations/{violation}/remediate', [SodController::class, 'remediate'])->name('sod.violations.remediate');
        Route::post('sod/violations/{violation}/false-positive', [SodController::class, 'falsePositive'])
            ->name('sod.violations.false-positive');
        Route::post('sod/violations/{violation}/escalate', [SodController::class, 'escalate'])->name('sod.violations.escalate');
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

    // ── Version comparison (Phase 9.8) — access mirrors the record's policy
    Route::get('versions/{alias}/{id}/diff', [VersionController::class, 'diff'])->name('versions.diff');

    // ── Record activity history (Phase 7.5) — access mirrors the record's policy
    Route::middleware('feature:audit-log-ui')
        ->get('audit-trail/{alias}/{id}', [AuditLogController::class, 'entity'])
        ->name('audit-trail.entity');

    // ── AI layer (Phase 14) ──────────────────────────────────────────
    // Assist sits next to the record it helps with rather than in an area
    // users must remember to visit (§C.9), so these are XHR endpoints
    // called from whatever page the user is already on.
    //
    // Throttled at the route as well as in BudgetService: the service
    // limits are per capability and configurable, this one is a flat
    // ceiling that holds even if a tenant sets its own limits high.
    Route::middleware(['feature:ai-assist', 'permission:use ai'])->group(function () {
        Route::post('ai/assist', [AiAssistController::class, 'store'])
            ->middleware('throttle:30,1')->name('ai.assist');
        Route::get('ai/interactions/{interaction}', [AiAssistController::class, 'show'])
            ->name('ai.interactions.show');
        Route::post('ai/interactions/{interaction}/decide', [AiAssistController::class, 'decide'])
            ->name('ai.interactions.decide');
        Route::post('ai/interactions/{interaction}/feedback', [AiAssistController::class, 'feedback'])
            ->name('ai.interactions.feedback');
    });

    Route::middleware(['feature:ai-atlas', 'permission:use ai', 'throttle:30,1'])
        ->post('ai/atlas', [AtlasController::class, 'ask'])
        ->name('ai.atlas');

    // ── Administration ───────────────────────────────────────────────
    Route::middleware('role:System Administrator|Control Function Head')->prefix('admin')->group(function () {
        // Roles & permissions (BRD §4) — who may do what, in one screen.
        // 'manage users' is held by the System Administrator alone.
        Route::middleware('permission:manage users')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('admin.roles');
            Route::post('roles', [RoleController::class, 'store'])->name('admin.roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
            Route::put('users/{user}/roles', [RoleController::class, 'updateUserRoles'])->name('admin.users.roles.update');

            // User management — create, invite, edit, suspend, soft-delete.
            // Static segments before the {user} wildcard.
            Route::get('users', [UserController::class, 'index'])->name('admin.users.index');
            Route::get('users/export', [UserController::class, 'export'])->name('admin.users.export');
            Route::post('users', [UserController::class, 'store'])->name('admin.users.store');
            Route::post('users/bulk', [UserController::class, 'bulk'])->name('admin.users.bulk');
            Route::put('users/{user}', [UserController::class, 'update'])->name('admin.users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
            Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite'])->name('admin.users.resend-invite');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('admin.users.reset-password');
            Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('admin.users.suspend');
            Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('admin.users.reactivate');
        });

        // CR-01: exception routing + SLA config. Its own permission, held by
        // the System Administrator — who deliberately gains no review or
        // closure rights with it.
        Route::middleware(['feature:exception-manager', 'permission:configure exception-routing'])->group(function () {
            Route::get('exception-routing', [ExceptionRoutingController::class, 'routing'])->name('admin.exception-routing');
            Route::post('exception-routing', [ExceptionRoutingController::class, 'storeRule'])->name('admin.exception-routing.store');
            Route::put('exception-routing/{rule}', [ExceptionRoutingController::class, 'updateRule'])->name('admin.exception-routing.update');
            Route::delete('exception-routing/{rule}', [ExceptionRoutingController::class, 'destroyRule'])->name('admin.exception-routing.destroy');
            Route::get('exception-sla', [ExceptionRoutingController::class, 'sla'])->name('admin.exception-sla');
            Route::post('exception-sla', [ExceptionRoutingController::class, 'storePolicy'])->name('admin.exception-sla.store');
            Route::put('exception-sla/{policy}', [ExceptionRoutingController::class, 'updatePolicy'])->name('admin.exception-sla.update');
        });

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

            // CR — Speak Up: metadata capture, anonymous route, retention,
            // reveal reason codes and the versioned NDPA notice.
            Route::get('speak-up', [SpeakUpSettingsController::class, 'edit'])->name('admin.speak-up');
            Route::put('speak-up', [SpeakUpSettingsController::class, 'update'])->name('admin.speak-up.update');

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

        // CR3 — the Activity Log now lives at Settings → Activity Log; the
        // old admin URL redirects so bookmarks and muscle memory survive.
        Route::redirect('audit-log', '/settings/activity-log')->name('admin.audit-log');

        // Regulatory content packs (Phase 8) — installation is a platform
        // operation, gated on its own permission inside the admin area.
        Route::middleware('feature:content-packs')->group(function () {
            Route::get('content-packs', [ContentPackController::class, 'index'])->name('admin.content-packs');

            Route::middleware('permission:install content-packs')->group(function () {
                Route::post('content-packs/preview', [ContentPackController::class, 'preview'])->name('admin.content-packs.preview');
                Route::post('content-packs/install', [ContentPackController::class, 'install'])->name('admin.content-packs.install');
            });
        });

        // Bulk Excel import (Phase 9.7) — dry-run first, all-or-nothing write.
        Route::middleware(['permission:import data', 'feature:bulk-import'])->group(function () {
            Route::get('import', [ImportController::class, 'index'])->name('admin.import');
            Route::get('import/{resource}/template', [ImportController::class, 'template'])->name('admin.import.template');
            Route::post('import/{resource}/dry-run', [ImportController::class, 'dryRun'])->name('admin.import.dry-run');
            Route::post('import/{resource}', [ImportController::class, 'store'])->name('admin.import.store');
        });

        // Data sources (Phase 12.1) — a live credential into a client's
        // core, so registration is administrative and approval is a
        // second person's act, gated on its own permission.
        Route::middleware(['feature:data-sources', 'permission:view data-sources'])->group(function () {
            Route::get('data-sources', [DataSourceController::class, 'index'])->name('admin.data-sources.index');
            Route::post('data-sources', [DataSourceController::class, 'store'])->name('admin.data-sources.store');
            Route::get('data-sources/{data_source}', [DataSourceController::class, 'show'])->name('admin.data-sources.show');
            Route::put('data-sources/{data_source}', [DataSourceController::class, 'update'])->name('admin.data-sources.update');
            Route::post('data-sources/{data_source}/approve', [DataSourceController::class, 'approve'])
                ->name('admin.data-sources.approve');
            Route::post('data-sources/{data_source}/test', [DataSourceController::class, 'test'])
                ->middleware('throttle:20,1')->name('admin.data-sources.test');
            Route::post('data-sources/{data_source}/resume', [DataSourceController::class, 'resume'])
                ->name('admin.data-sources.resume');

            Route::post('data-sources/{data_source}/datasets', [DataSourceController::class, 'storeDataset'])
                ->name('admin.data-sources.datasets.store');
            Route::put('data-sources/{data_source}/datasets/{dataset}', [DataSourceController::class, 'updateDataset'])
                ->name('admin.data-sources.datasets.update');
            Route::post('data-sources/{data_source}/datasets/{dataset}/capture', [DataSourceController::class, 'capture'])
                ->middleware('throttle:20,1')->name('admin.data-sources.datasets.capture');
            Route::post('data-sources/{data_source}/datasets/{dataset}/authorise-retention', [DataSourceController::class, 'authoriseRetention'])
                ->name('admin.data-sources.datasets.authorise-retention');
            Route::delete('data-sources/{data_source}/datasets/{dataset}/authorise-retention', [DataSourceController::class, 'revokeRetention'])
                ->name('admin.data-sources.datasets.revoke-retention');
        });

        // AI governance (Phase 14.4, §C.8). Its own permission rather than
        // 'manage settings': an organisation demonstrating AI governance to
        // a regulator needs turning the models on to be a named authority
        // held by named people, not a line item inside general admin.
        Route::middleware('feature:ai-governance')->group(function () {
            Route::get('ai', [AiGovernanceController::class, 'index'])->name('admin.ai.index');
            Route::get('ai/log', [AiGovernanceController::class, 'log'])
                ->middleware('permission:view ai-log')->name('admin.ai.log');
            Route::get('ai/log/export', [AiGovernanceController::class, 'exportLog'])
                ->middleware('permission:export ai-log')->name('admin.ai.log.export');

            Route::middleware('permission:manage ai')->group(function () {
                Route::put('ai/capabilities', [AiGovernanceController::class, 'updateCapability'])
                    ->name('admin.ai.capabilities.update');
                Route::put('ai/budget', [AiGovernanceController::class, 'updateBudget'])
                    ->name('admin.ai.budget.update');
                Route::get('ai/prompts', [AiGovernanceController::class, 'prompts'])
                    ->name('admin.ai.prompts');
                Route::post('ai/prompts', [AiGovernanceController::class, 'storePrompt'])
                    ->name('admin.ai.prompts.store');
                Route::post('ai/prompts/{prompt}/activate', [AiGovernanceController::class, 'activatePrompt'])
                    ->name('admin.ai.prompts.activate');
            });
        });

        // Omnichannel messaging (Phase 15.3/15.4): templates, delivery
        // log, per-channel cost tracking, Meta template sync.
        Route::middleware('permission:manage messaging')->group(function () {
            Route::get('messaging', [MessagingController::class, 'index'])->name('admin.messaging');
            Route::post('messaging/sync-templates', [MessagingController::class, 'syncTemplates'])
                ->name('admin.messaging.sync-templates');
        });

        Route::get('integrations', [IntegrationController::class, 'index'])->name('admin.integrations');
        Route::post('integrations', [IntegrationController::class, 'store'])->name('admin.integrations.store');
        Route::post('integrations/sync-logs/{sync_log}/replay', [IntegrationController::class, 'replay'])->name('admin.integrations.replay');
    });
});
