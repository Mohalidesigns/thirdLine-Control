<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoConfiguration;
use App\Services\OidcService;
use App\Services\SamlService;
use App\Services\SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login-side SSO flows. Admin configuration lives in
 * Admin\SsoConfigurationController; this controller only consumes
 * ACTIVE (approved + enabled) configurations — except test mode, which an
 * authorised administrator may run against an unapproved configuration
 * and which never creates a session.
 */
class SsoController extends Controller
{
    public function __construct(
        private SsoService $sso,
        private OidcService $oidc,
        private SamlService $saml,
    ) {}

    public function redirect(Request $request, SsoConfiguration $ssoConfiguration): RedirectResponse
    {
        $testMode = $request->boolean('test') && ($request->user()?->can('manage sso') ?? false);

        abort_unless($ssoConfiguration->isActive() || $testMode, 404);

        $url = $ssoConfiguration->protocol === 'oidc'
            ? $this->oidc->authorizationUrl($ssoConfiguration, $testMode)
            : $this->saml->authnRequestUrl($ssoConfiguration, $testMode);

        return redirect()->away($url);
    }

    /** OIDC authorization-code callback (GET). */
    public function callback(Request $request, SsoConfiguration $ssoConfiguration): Response|RedirectResponse
    {
        $testMode = (bool) session('oidc.test');

        abort_unless($ssoConfiguration->protocol === 'oidc', 404);
        abort_unless($ssoConfiguration->isActive() || $testMode, 404);

        $claims = $this->oidc->handleCallback($ssoConfiguration, $request);

        return $this->complete($request, $ssoConfiguration, $claims, $testMode);
    }

    /** SAML assertion consumer service (POST, CSRF-exempt). */
    public function acs(Request $request, SsoConfiguration $ssoConfiguration): Response|RedirectResponse
    {
        $testMode = (bool) session('saml.test');

        abort_unless($ssoConfiguration->protocol === 'saml2', 404);
        abort_unless($ssoConfiguration->isActive() || $testMode, 404);

        $claims = $this->saml->processAssertion($ssoConfiguration);

        return $this->complete($request, $ssoConfiguration, $claims, $testMode);
    }

    /** SP metadata for the IdP administrator. Public by design. */
    public function metadata(SsoConfiguration $ssoConfiguration): HttpResponse
    {
        abort_unless($ssoConfiguration->protocol === 'saml2', 404);

        return response($this->saml->metadataXml($ssoConfiguration), 200, [
            'Content-Type' => 'application/samlmetadata+xml',
        ]);
    }

    private function complete(Request $request, SsoConfiguration $config, array $claims, bool $testMode): Response|RedirectResponse
    {
        if ($testMode) {
            session()->forget(['oidc.test', 'saml.test']);

            // Assertion validated; report the mapping and stop — no session.
            $config->auditAction('sso_test_connection_succeeded');

            return Inertia::render('Admin/SsoTestResult', [
                'configuration' => $config->only(['id', 'display_name', 'protocol']),
                'claims' => collect($claims)->except(['at_hash', 'rt_hash'])->all(),
                'resolvedRole' => $this->sso->roleForClaims($config, $claims)->name,
            ]);
        }

        $user = $this->sso->resolveUser($config, $claims);

        Auth::login($user);
        $request->session()->regenerate();

        $user->auditAction('sso_login', null, ['config_id' => $config->id, 'protocol' => $config->protocol]);

        return redirect()->intended(route('dashboard'));
    }
}
