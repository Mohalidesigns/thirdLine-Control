<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The forced first-login password change for users created with a
 * temporary password. RequirePasswordChange funnels them here.
 */
class ForcePasswordController extends Controller
{
    public function __construct(private AuditTrailService $audit) {}

    public function edit(): Response
    {
        return Inertia::render('Auth/ForcePassword');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $request->string('password'),
            'must_change_password' => false,
        ])->save();

        $this->audit->record('password-changed', $user, null, ['forced' => true]);

        return redirect()->route('dashboard')->with('success', 'Your password has been changed.');
    }
}
