<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\UserInvitedNotification;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * User administration (BRD §4) — gated on 'manage users', the same
 * permission as Roles & Permissions, and bound by the same invariants:
 * a tenant always keeps at least one active System Administrator, and
 * every change lands in the audit trail with before and after.
 *
 * Deletion is always soft — a user's historical attribution on controls,
 * tests, exceptions and evidence must survive them leaving.
 */
class UserController extends Controller
{
    private const INVITE_VALID_DAYS = 7;

    private const RESET_VALID_HOURS = 24;

    public function __construct(private AuditTrailService $audit) {}

    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->with(['roles:id,name', 'unit:id,name', 'manager:id,name'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query
                ->whereHas('roles', fn ($q) => $q->where('name', $request->string('role'))))
            ->when($request->filled('unit'), fn ($query) => $query->where('unit_id', $request->integer('unit')))
            ->when($request->filled('status'), fn ($query) => match ($request->string('status')->value()) {
                'Suspended' => $query->where('is_active', false),
                'Invited' => $query->where('is_active', true)
                    ->whereNotNull('invited_at')->whereNull('invite_accepted_at'),
                'Active' => $query->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('invited_at')->orWhereNotNull('invite_accepted_at')),
                default => $query,
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'unit', 'status']),
            'roles' => Role::orderBy('id')->pluck('name'),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'managers' => User::where('tenant_id', $tenantId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = new User([
            'tenant_id' => $request->user()->tenant_id,
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'position' => $validated['position'] ?? null,
            'unit_id' => $validated['unit_id'] ?? null,
            'reports_to' => $validated['reports_to'] ?? null,
            'is_active' => true,
        ]);

        if ($validated['password_mode'] === 'temporary') {
            $user->password = $validated['temporary_password'];
            $user->must_change_password = true;
        } else {
            // Invited users get an unguessable placeholder they never see;
            // the signed set-password link replaces it.
            $user->password = Str::random(40);
            $user->invited_at = now();
        }

        // The Auditable trait writes the 'created' event (secrets redacted);
        // role assignment is a pivot change it cannot see, so that gets its
        // own named record.
        $user->save();
        $user->syncRoles($validated['roles']);
        $this->audit->record('roles-updated', $user, ['roles' => []], ['roles' => $validated['roles']]);

        if ($validated['password_mode'] === 'invite') {
            $this->sendInvite($user, $request->user()->name);
        }

        return back()->with('success', $validated['password_mode'] === 'invite'
            ? "{$user->name} created — invitation sent to {$user->email}."
            : "{$user->name} created with a temporary password. They must change it at first login.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);

        $validated = $request->validated();
        $rolesBefore = $user->roles->pluck('name')->values()->all();
        $this->guardLastAdministrator($user, $validated['roles']);

        // Attribute changes are audited by the Auditable trait; the role
        // pivot sync is not, so it gets its own named record.
        $user->fill([
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'position' => $validated['position'] ?? null,
            'unit_id' => $validated['unit_id'] ?? null,
            'reports_to' => $validated['reports_to'] ?? null,
        ]);
        $user->save();
        $user->syncRoles($validated['roles']);

        if ($rolesBefore !== array_values($validated['roles'])) {
            $this->audit->record('roles-updated', $user,
                ['roles' => $rolesBefore], ['roles' => array_values($validated['roles'])]);
        }

        return back()->with('success', "{$user->name} updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $this->guardLastAdministrator($user, []);

        // Soft delete — historical attribution on controls, tests,
        // exceptions and evidence keeps pointing at this user. The
        // Auditable trait records both the deactivation and the deletion.
        $user->update(['is_active' => false]);
        $user->delete();

        return back()->with('success', "{$user->name} deleted. Their historical activity is preserved.");
    }

    public function resendInvite(Request $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);

        if ($user->status() !== 'Invited') {
            throw ValidationException::withMessages([
                'user' => 'Only a user with a pending invitation can be re-invited.',
            ]);
        }

        // Refreshing the invite timestamp is bookkeeping, not an edit — the
        // named 'invite-resent' record below is the audit entry.
        $user->forceFill(['invited_at' => now()])->saveQuietly();
        $this->sendInvite($user, $request->user()->name);
        $this->audit->record('invite-resent', $user, null, ['email' => $user->email]);

        return back()->with('success', "Invitation re-sent to {$user->email}.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);

        $expires = now()->addHours(self::RESET_VALID_HOURS);
        $user->notify(new PasswordResetLinkNotification(
            $this->setPasswordUrl($user, $expires),
            $expires->format('d M Y H:i'),
        ));

        $this->audit->record('password-reset-link-sent', $user, null, ['email' => $user->email]);

        return back()->with('success', "Password reset link sent to {$user->email}.");
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);
        $this->suspendOne($request, $user);

        return back()->with('success', "{$user->name} suspended. They can no longer sign in.");
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->assertSameTenant($request, $user);

        // Audited by the Auditable trait as an is_active update.
        $user->update(['is_active' => true]);

        return back()->with('success', "{$user->name} reactivated.");
    }

    /** Bulk assign-role / suspend over a selection from the list view. */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign-role', 'suspend'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'role' => ['required_if:action,assign-role', 'nullable', 'string', Rule::exists('roles', 'name')],
        ]);

        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('id', $validated['ids'])->get();

        $done = 0;
        $skipped = [];
        foreach ($users as $user) {
            try {
                if ($validated['action'] === 'assign-role') {
                    $before = $user->roles->pluck('name')->values()->all();
                    if (! in_array($validated['role'], $before, true)) {
                        $user->assignRole($validated['role']);
                        $this->audit->record('roles-updated', $user,
                            ['roles' => $before],
                            ['roles' => [...$before, $validated['role']]]);
                    }
                } else {
                    $this->suspendOne($request, $user);
                }
                $done++;
            } catch (ValidationException) {
                $skipped[] = $user->name;
            }
        }

        $message = $validated['action'] === 'assign-role'
            ? "Role '{$validated['role']}' assigned to {$done} user(s)."
            : "{$done} user(s) suspended.";
        if ($skipped !== []) {
            $message .= ' Skipped: '.implode(', ', $skipped).'.';
        }

        return back()->with($skipped === [] ? 'success' : 'warning', $message);
    }

    /** CSV export of the current tenant's users. */
    public function export(Request $request): StreamedResponse
    {
        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->with(['roles:id,name', 'unit:id,name'])
            ->orderBy('name')->get();

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Job title', 'Unit', 'Roles', 'Status', 'Last login']);
            foreach ($users as $user) {
                fputcsv($out, [
                    $user->name,
                    $user->email,
                    $user->position,
                    $user->unit?->name,
                    $user->roles->pluck('name')->implode('; '),
                    $user->status(),
                    $user->last_login_at?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'users-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function suspendOne(Request $request, User $user): void
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot suspend your own account.',
            ]);
        }

        if (! $user->is_active) {
            return;
        }

        $this->guardLastAdministrator($user, []);

        // Audited by the Auditable trait as an is_active update.
        $user->update(['is_active' => false]);
    }

    private function sendInvite(User $user, string $invitedBy): void
    {
        $expires = now()->addDays(self::INVITE_VALID_DAYS);
        $user->notify(new UserInvitedNotification(
            $this->setPasswordUrl($user, $expires),
            $invitedBy,
            $expires->format('d M Y'),
        ));
    }

    /**
     * Signed, expiring set-password link. The password-hash fragment makes
     * the link single-use: once the password changes, the fragment no
     * longer matches and the link dies even inside its signature window.
     */
    private function setPasswordUrl(User $user, \DateTimeInterface $expires): string
    {
        return URL::temporarySignedRoute('invite.accept', $expires, [
            'user' => $user->id,
            'h' => substr(sha1((string) $user->password), 0, 16),
        ]);
    }

    /**
     * Refuses any change that would leave the tenant without an active
     * System Administrator. $nextRoles is the role set the user would hold
     * afterwards — pass [] for suspension or deletion.
     */
    private function guardLastAdministrator(User $user, array $nextRoles): void
    {
        $losesAdmin = $user->hasRole('System Administrator')
            && ! in_array('System Administrator', $nextRoles, true);

        if (! $losesAdmin) {
            return;
        }

        $anotherExists = User::where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'System Administrator'))
            ->exists();

        if (! $anotherExists) {
            throw ValidationException::withMessages([
                'user' => 'A tenant must keep at least one active System Administrator.',
            ]);
        }
    }

    private function assertSameTenant(Request $request, User $user): void
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);
    }

    /** The list-view shape. */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'position' => $user->position,
            'unit' => $user->unit ? ['id' => $user->unit->id, 'name' => $user->unit->name] : null,
            'manager' => $user->manager ? ['id' => $user->manager->id, 'name' => $user->manager->name] : null,
            'roles' => $user->roles->pluck('name')->values(),
            'status' => $user->status(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
