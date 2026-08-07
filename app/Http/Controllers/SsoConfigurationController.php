<?php

namespace App\Http\Controllers;

use App\Http\Requests\SsoConfigurationRequest;
use App\Models\SsoConfiguration;
use App\Services\OidcService;
use App\Services\SamlService;
use App\Services\SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class SsoConfigurationController extends Controller
{
    public function __construct(
        private SsoService $sso,
        private OidcService $oidc,
        private SamlService $saml,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SsoConfiguration::class);

        return Inertia::render('Admin/Sso', [
            'configurations' => SsoConfiguration::query()
                ->with(['creator:id,name', 'approver:id,name', 'proposer:id,name', 'defaultRole:id,name'])
                ->orderBy('display_name')
                ->get()
                ->map(fn (SsoConfiguration $config) => [
                    ...$config->only([
                        'id', 'protocol', 'display_name', 'entity_id', 'sso_url', 'slo_url',
                        'oidc_issuer', 'oidc_client_id', 'oidc_discovery_url', 'attribute_map',
                        'jit_provisioning', 'default_role_id', 'allowed_email_domains',
                        'is_enabled', 'verified_at', 'approved_at', 'pending_changes',
                    ]),
                    'is_active' => $config->isActive(),
                    'has_pending_changes' => $config->hasPendingChanges(),
                    'creator' => $config->creator?->name,
                    'approver' => $config->approver?->name,
                    'proposer' => $config->proposer?->name,
                    'can_approve' => $request->user()->can('approve', $config),
                    'metadata_url' => $config->protocol === 'saml2' ? route('sso.metadata', $config) : null,
                ]),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'userId' => $request->user()->id,
        ]);
    }

    public function store(SsoConfigurationRequest $request): RedirectResponse
    {
        $this->authorize('create', SsoConfiguration::class);

        $this->sso->create($request->user(), $request->validated());

        return back()->with('success', 'SSO configuration created. A second System Administrator must approve it before it takes effect.');
    }

    public function update(SsoConfigurationRequest $request, SsoConfiguration $ssoConfiguration): RedirectResponse
    {
        $this->authorize('update', $ssoConfiguration);

        $this->sso->proposeUpdate($request->user(), $ssoConfiguration, $request->validated());

        return back()->with('success', 'Changes proposed. A second System Administrator must approve them before they take effect.');
    }

    public function approve(Request $request, SsoConfiguration $ssoConfiguration): RedirectResponse
    {
        $this->authorize('approve', $ssoConfiguration);

        $this->sso->approve($request->user(), $ssoConfiguration);

        return back()->with('success', 'SSO configuration approved and in effect.');
    }

    public function reject(Request $request, SsoConfiguration $ssoConfiguration): RedirectResponse
    {
        $this->authorize('approve', $ssoConfiguration);

        $this->sso->reject($request->user(), $ssoConfiguration);

        return back()->with('success', 'The pending SSO change was rejected.');
    }

    /** Settings-level validation with no session created (Phase 7.1). */
    public function test(Request $request, SsoConfiguration $ssoConfiguration): RedirectResponse
    {
        $this->authorize('test', $ssoConfiguration);

        $result = $ssoConfiguration->protocol === 'oidc'
            ? $this->oidc->testConnection($ssoConfiguration)
            : $this->saml->testConnection($ssoConfiguration);

        if ($result['ok']) {
            $ssoConfiguration->forceFill(['verified_at' => now()])->save();
            $ssoConfiguration->auditAction('sso_test_connection_succeeded', null, ['message' => $result['message']]);

            return back()->with('success', 'Test passed: '.$result['message']);
        }

        $ssoConfiguration->auditAction('sso_test_connection_failed', null, ['message' => $result['message']]);

        return back()->with('error', 'Test failed: '.$result['message']);
    }
}
