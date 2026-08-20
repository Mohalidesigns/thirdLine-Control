<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed set-password flow shared by user invitations and
 * administrator-triggered password resets. The URL carries a temporary
 * signature plus a fragment of the current password hash, so a link dies
 * the moment the password changes — single use, even inside its window.
 */
class SetPasswordController extends Controller
{
    public function __construct(private AuditTrailService $audit) {}

    public function show(Request $request, User $user): Response
    {
        $this->assertUsable($request, $user);

        return Inertia::render('Auth/SetPassword', [
            'name' => $user->name,
            'email' => $user->email,
            'isInvite' => $user->status() === 'Invited',
            // POST back to the same signed URL so the signature check
            // covers both legs of the flow.
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        $this->assertUsable($request, $user);

        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $wasInvite = $user->status() === 'Invited';

        $user->forceFill([
            'password' => $request->string('password'),
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'invite_accepted_at' => $user->invite_accepted_at ?? ($wasInvite ? now() : null),
        ])->save();

        $this->audit->record($wasInvite ? 'invite-accepted' : 'password-reset', $user, null, [
            'email' => $user->email,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->route('dashboard')
            ->with('success', $wasInvite ? 'Welcome — your account is active.' : 'Your password has been changed.');
    }

    private function assertUsable(Request $request, User $user): void
    {
        // The 'signed' middleware has already validated signature + expiry;
        // the hash fragment makes the link single-use.
        abort_unless(hash_equals(
            substr(sha1((string) $user->password), 0, 16),
            (string) $request->query('h'),
        ), 403, 'This link has already been used or superseded.');

        abort_unless($user->is_active, 403, 'This account has been suspended.');
    }
}
