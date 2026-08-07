<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends Controller
{
    public function __construct(private MfaService $mfa) {}

    // ── Challenge (each session) ─────────────────────────────────────

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasMfaEnabled() || $request->session()->get('mfa.verified')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/MfaChallenge', [
            'emailOtpSent' => (bool) $request->session()->get('mfa.email_otp_sent'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->mfa->verifyChallenge($request->user(), $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }

        $request->session()->regenerate();
        $request->session()->put('mfa.verified', true);
        $request->session()->forget('mfa.email_otp_sent');

        return redirect()->intended(route('dashboard'));
    }

    public function sendEmailOtp(Request $request): RedirectResponse
    {
        $this->mfa->sendEmailOtp($request->user());
        $request->session()->put('mfa.email_otp_sent', true);

        return back()->with('info', 'A one-time code has been emailed to you.');
    }

    // ── Enrolment (Settings → Security) ──────────────────────────────

    public function setup(Request $request): Response
    {
        $user = $request->user();

        $enrolment = $user->hasMfaEnabled() ? null : $this->mfa->beginEnrolment($user);

        return Inertia::render('Settings/Security', [
            'mfaEnabled' => $user->hasMfaEnabled(),
            'mfaEnforced' => $this->mfa->isEnforcedFor($user),
            'enrolment' => $enrolment ? [
                'secret' => $enrolment['secret'],
                'qr_svg' => $enrolment['qr_svg'],
            ] : null,
            'recoveryCodes' => $request->session()->get('mfa.recovery_codes'),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $codes = $this->mfa->confirmEnrolment($request->user(), $request->input('code'));

        $request->session()->put('mfa.verified', true);

        // Shown exactly once on the next render, then gone.
        return redirect()->route('mfa.setup')
            ->with('success', 'Multi-factor authentication is enabled.')
            ->with('mfa.recovery_codes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        if ($this->mfa->isEnforcedFor($request->user())) {
            throw ValidationException::withMessages([
                'password' => 'MFA is enforced for your role by tenant policy and cannot be disabled.',
            ]);
        }

        $this->mfa->disable($request->user());

        return redirect()->route('mfa.setup')->with('success', 'Multi-factor authentication disabled.');
    }
}
