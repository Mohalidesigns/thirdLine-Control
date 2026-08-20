<?php

namespace Tests\e2e;

use App\Models\ControlException;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TC-02 — users, roles and permission enforcement.
 *
 * The plan's rule for this module is the important one: *"UI hiding a
 * button is not authorisation."* Every negative here is a direct call to
 * the endpoint with a valid session for a role that should not have it.
 *
 * TC-02-05 is executed as a sweep across the whole routing table rather
 * than a hand-picked sample, because a hand-picked sample tests the
 * routes someone already thought about.
 */
class AuthorisationMatrixTest extends E2ETestCase
{
    /** Accounts to draw an unauthorised caller from, least-privileged first. */
    private const CALLERS = ['owner', 'manager', 'exec', 'officer', 'cfh', 'admin'];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // -----------------------------------------------------------------
    // Requirement parsing
    // -----------------------------------------------------------------

    /**
     * The role/permission requirements a route declares. Each entry is a
     * set of alternatives; the caller must satisfy at least one of each
     * entry, and the entries themselves are ANDed.
     *
     * @return array<int, array{type: string, options: array<int, string>}>
     */
    private function requirementsFor(Route $route): array
    {
        $requirements = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['role', 'permission', 'role_or_permission'] as $type) {
                if (str_starts_with($middleware, $type.':')) {
                    $requirements[] = [
                        'type' => $type,
                        'options' => explode('|', substr($middleware, strlen($type) + 1)),
                    ];
                }
            }
        }

        return $requirements;
    }

    private function satisfies(User $user, array $requirement): bool
    {
        return match ($requirement['type']) {
            'role' => $user->hasAnyRole($requirement['options']),
            'permission' => $user->hasAnyPermission($requirement['options']),
            'role_or_permission' => $user->hasAnyRole($requirement['options'])
                || $user->hasAnyPermission($requirement['options']),
            default => true,
        };
    }

    /** The least-privileged seeded account that fails at least one requirement. */
    private function unauthorisedCallerFor(array $requirements): ?User
    {
        foreach (self::CALLERS as $key) {
            $user = $this->account($key);

            foreach ($requirements as $requirement) {
                if (! $this->satisfies($user, $requirement)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /** Routes that mutate state and declare a role/permission requirement. */
    private function gatedMutatingRoutes(bool $withParameters): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->filter(fn (Route $r) => str_contains($r->uri(), '{') === $withParameters)
            // The one disabled feature flag returns 404 from feature
            // middleware, which would mask the authorisation result.
            ->reject(fn (Route $r) => str_contains($r->uri(), 'ussd'))
            ->filter(fn (Route $r) => $this->requirementsFor($r) !== [])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // TC-02-05 — the sweep
    // -----------------------------------------------------------------

    /**
     * Every parameterless mutating route that declares a role or
     * permission requirement, called by an account that does not meet it.
     *
     * A 2xx here means an unauthorised user performed a restricted action
     * — Critical. A 5xx means the authorisation layer threw instead of
     * refusing, which the plan also rates a failure ("403, not 200 or
     * 500").
     */
    public function test_tc_02_05_no_gated_route_admits_an_unauthorised_caller(): void
    {
        $routes = $this->gatedMutatingRoutes(withParameters: false);

        $this->assertGreaterThan(30, count($routes), 'Sweep found suspiciously few gated routes — check the parser.');

        $admitted = [];
        $errored = [];
        $skipped = 0;
        $checked = 0;

        foreach ($routes as $route) {
            $caller = $this->unauthorisedCallerFor($this->requirementsFor($route));

            if (! $caller) {
                $skipped++;   // every seeded role satisfies it

                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.ltrim($route->uri(), '/');

            $response = $this->actingAs($caller)->call($method, $uri);
            $checked++;

            $label = $method.' '.$uri.'  (as '.$caller->email.')';

            if ($response->isSuccessful() || $response->isRedirect() && ! $this->isRedirectToLogin($response)) {
                // A redirect from a mutating route usually means the
                // controller ran and called back(). Treat as admitted.
                $admitted[] = $label.'  → '.$response->status();
            } elseif ($response->status() >= 500) {
                $errored[] = $label.'  → '.$response->status();
            }
        }

        $this->assertSame(
            [],
            $admitted,
            "TC-02-05 CRITICAL: unauthorised callers were admitted to restricted routes:\n  "
            .implode("\n  ", $admitted)
        );

        $this->assertSame(
            [],
            $errored,
            'TC-02-05: authorisation produced a server error instead of a refusal '
            ."(the plan requires 403, not 500):\n  ".implode("\n  ", $errored)
        );

        // Recorded so the evidence states the sweep's reach, not just its verdict.
        fwrite(STDERR, "\n[TC-02-05] parameterless gated routes: checked {$checked}, skipped {$skipped} (all roles satisfy)\n");
    }

    private function isRedirectToLogin($response): bool
    {
        return str_contains((string) $response->headers->get('Location'), '/login');
    }

    /**
     * The same sweep over parameterised routes, using a real seeded record
     * so route-model binding resolves and the authorisation layer is
     * genuinely reached. Restricted to the exception routes, where a
     * concrete, correct id is available for every parameter.
     */
    public function test_tc_02_05_parameterised_exception_routes_refuse_unauthorised_callers(): void
    {
        $exception = ControlException::withoutGlobalScopes()
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->firstOrFail();

        $routes = collect($this->gatedMutatingRoutes(withParameters: true))
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'exceptions/{exception}'))
            ->values();

        $admitted = [];

        foreach ($routes as $route) {
            $caller = $this->unauthorisedCallerFor($this->requirementsFor($route));

            if (! $caller) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.str_replace('{exception}', (string) $exception->id, $route->uri());

            $response = $this->actingAs($caller)->call($method, $uri);

            if ($response->isSuccessful()) {
                $admitted[] = $method.' '.$uri.' (as '.$caller->email.') → '.$response->status();
            }
        }

        $this->assertSame([], $admitted, "Unauthorised callers admitted:\n  ".implode("\n  ", $admitted));
    }

    // -----------------------------------------------------------------
    // TC-02-06 — privilege escalation
    // -----------------------------------------------------------------

    public function test_tc_02_06_non_admin_cannot_reach_role_administration(): void
    {
        foreach (['officer', 'owner', 'manager', 'exec', 'cfh'] as $account) {
            $user = $this->account($account);

            $this->actingAs($user)
                ->get(route('admin.roles'))
                ->assertForbidden();

            $this->actingAs($user)
                ->put(route('admin.users.roles.update', $user), ['roles' => ['System Administrator']])
                ->assertForbidden();

            $this->assertFalse(
                $user->fresh()->hasRole('System Administrator'),
                "TC-02-06 CRITICAL: {$account} granted itself System Administrator."
            );
        }
    }

    public function test_tc_02_06_user_cannot_grant_themselves_a_role_they_lack(): void
    {
        $officer = $this->account('officer');

        $this->actingAs($officer)
            ->put(route('admin.users.roles.update', $officer), [
                'roles' => ['Control Officer', 'Control Function Head'],
            ])
            ->assertForbidden();

        $this->assertFalse(
            $officer->fresh()->hasRole('Control Function Head'),
            'TC-02-06 CRITICAL: a Control Officer escalated itself to Control Function Head.'
        );
    }

    public function test_tc_02_06_system_administrator_role_is_immutable(): void
    {
        $role = Role::where('name', 'System Administrator')->firstOrFail();

        $this->actingAs($this->account('admin'))
            ->put(route('admin.roles.update', $role), ['permissions' => ['view controls']])
            ->assertSessionHasErrors('role');

        $this->assertGreaterThan(
            150,
            $role->fresh()->permissions()->count(),
            'The System Administrator role must retain every permission.'
        );
    }

    public function test_seeded_roles_cannot_be_deleted(): void
    {
        foreach (['Control Function Head', 'Control Owner'] as $name) {
            $role = Role::where('name', $name)->firstOrFail();

            $this->actingAs($this->account('admin'))
                ->delete(route('admin.roles.destroy', $role))
                ->assertSessionHasErrors('role');

            $this->assertNotNull(
                Role::where('name', $name)->first(),
                "Seeded role {$name} was deleted."
            );
        }
    }

    // -----------------------------------------------------------------
    // TC-02-08 — the last administrator
    // -----------------------------------------------------------------

    public function test_tc_02_08_the_last_active_administrator_cannot_be_demoted(): void
    {
        $admin = $this->account('admin');

        // Confirm the precondition rather than assuming it.
        $others = User::withoutGlobalScopes()
            ->where('tenant_id', $admin->tenant_id)
            ->where('id', '!=', $admin->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'System Administrator'))
            ->count();

        $this->assertSame(0, $others, 'Fixture assumption broken: more than one active administrator.');

        $this->actingAs($admin)
            ->put(route('admin.users.roles.update', $admin), ['roles' => ['Control Officer']])
            ->assertSessionHasErrors('roles');

        $this->assertTrue(
            $admin->fresh()->hasRole('System Administrator'),
            'TC-02-08: the last active System Administrator was demoted.'
        );
    }

    // -----------------------------------------------------------------
    // TC-02-02 — role changes take effect immediately
    // -----------------------------------------------------------------

    public function test_tc_02_02_role_change_grants_and_revokes_immediately(): void
    {
        $user = $this->account('owner');

        $this->assertFalse($user->can('close exceptions'));

        $this->actingAs($this->account('admin'))
            ->put(route('admin.users.roles.update', $user), ['roles' => ['Control Function Head']])
            ->assertSessionHasNoErrors();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            $user->fresh()->hasRole('Control Function Head'),
            'The new role was not applied.'
        );

        $this->assertFalse(
            $user->fresh()->hasRole('Control Owner'),
            'TC-02-02: the previous role was not revoked — syncRoles must replace, not append.'
        );
    }

    // -----------------------------------------------------------------
    // TC-01-03 / TC-02-05 — the deactivated user
    // -----------------------------------------------------------------

    public function test_tc_01_03_deactivated_user_cannot_authenticate(): void
    {
        $response = $this->post(route('login'), [
            'email' => self::ACCOUNTS['inactive'],
            'password' => 'password',
        ]);

        $this->assertGuest('web');
        $response->assertSessionHasErrors();
    }

    public function test_tc_01_02_login_failure_does_not_reveal_whether_the_account_exists(): void
    {
        // Read the message from the thrown ValidationException rather than
        // from flashed session data, which is only readable on the next
        // request. This gets the exact string the user would be shown.
        $messageFor = function (string $email, string $password): ?string {
            $this->flushSession();
            $this->withoutExceptionHandling();

            try {
                $this->post(route('login'), ['email' => $email, 'password' => $password]);

                return null;
            } catch (ValidationException $e) {
                return $e->errors()['email'][0] ?? null;
            } finally {
                $this->withExceptionHandling();
            }
        };

        $unknownAccount = $messageFor('no-such-user@secondline.test', 'password');
        $wrongPassword = $messageFor(self::ACCOUNTS['officer'], 'definitely-not-the-password');

        $this->assertNotNull($unknownAccount, 'An unknown account must still produce an error.');

        $this->assertSame(
            $unknownAccount,
            $wrongPassword,
            'TC-01-02: the message must not distinguish an unknown account from a wrong password — '
            ."got '{$unknownAccount}' vs '{$wrongPassword}', which permits account enumeration."
        );

        $this->assertGuest('web');
    }

    /**
     * TC-02-03 / TC-02-05. Deactivation must end access, not merely
     * prevent the next login.
     *
     * Executed as a real session: log in with credentials, confirm access,
     * deactivate the account out of band (as an administrator would), then
     * re-request with the same session. actingAs() is deliberately not
     * used here — the point is to prove how a genuine session behaves.
     */
    public function test_tc_02_03_deactivating_a_user_terminates_their_live_session(): void
    {
        $user = $this->account('officer');

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);

        // Baseline: the session works before deactivation.
        $this->get(route('exceptions.index'))->assertOk();

        // An administrator deactivates the account mid-session.
        $user->forceFill(['is_active' => false])->saveQuietly();

        $after = $this->get(route('exceptions.index'));

        $this->assertFalse(
            $after->isSuccessful(),
            'TC-02-03: a user deactivated mid-session retained full access. is_active is checked only in '
            .'LoginRequest::authenticate(); nothing re-checks it per request, so a dismissed employee keeps '
            .'every permission of their role until the session expires (SESSION_LIFETIME=120 minutes, '
            .'renewed on each request, so in practice for as long as they keep using it).'
        );
    }
}
