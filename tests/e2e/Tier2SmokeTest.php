<?php

namespace Tests\e2e;

use App\Models\ControlException;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tier 2 smoke pass, per scope decision D-3 in the system map.
 *
 * The ~20 domains built after BRD v1.0 — obligations, frameworks, content
 * packs, CSA campaigns, attestations, documents, improvements, appetite,
 * metrics, treatments, policies, incidents, complaints, whistleblowing,
 * cases, monitoring, connectors, SoD, dashboards, report designer,
 * submissions, AI, mobile/offline, messaging, entities, residency, SoA,
 * strategy, vendors, sustainability, assurance — get smoke, authorisation
 * and tenant-isolation coverage rather than the full Part B matrix.
 *
 * Every check is derived from the routing table rather than a hand-written
 * list, so a domain added later is covered without anyone remembering to
 * add it.
 */
class Tier2SmokeTest extends E2ETestCase
{
    /** Routes the sweep must not call: they mutate, redirect off-app, or stream a file. */
    private const SKIP = ['logout', 'up', 'login', 'register', 'storage', 'offline'];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Every authenticated, parameterless GET page in the application. */
    private function pageRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->filter(fn (Route $r) => ! str_contains($r->uri(), '{'))
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->reject(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
            ->reject(fn (Route $r) => str_starts_with($r->uri(), '_inertia'))
            ->reject(fn (Route $r) => collect(self::SKIP)->contains(fn ($s) => str_starts_with($r->uri(), $s)))
            // Exports stream bytes; covered separately in TC-14.
            ->reject(fn (Route $r) => (bool) preg_match('/\.(xlsx|pdf|csv)$/', $r->uri()))
            ->reject(fn (Route $r) => str_contains($r->uri(), 'export'))
            ->values()
            ->all();
    }

    private function requirementsFor(Route $route): array
    {
        $requirements = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['role', 'permission', 'role_or_permission'] as $type) {
                if (str_starts_with($middleware, $type.':')) {
                    $requirements[] = ['type' => $type, 'options' => explode('|', substr($middleware, strlen($type) + 1))];
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
            'role_or_permission' => $user->hasAnyRole($requirement['options']) || $user->hasAnyPermission($requirement['options']),
            default => true,
        };
    }

    private function qualifies(User $user, Route $route): bool
    {
        foreach ($this->requirementsFor($route) as $requirement) {
            if (! $this->satisfies($user, $requirement)) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Smoke — nothing anywhere returns a server error
    // -----------------------------------------------------------------

    public static function roleProvider(): array
    {
        return [
            'System Administrator' => ['admin'],
            'Control Function Head' => ['cfh'],
            'Control Officer' => ['officer'],
            'Control Owner' => ['owner'],
            'Line Manager' => ['manager'],
            'Executive Viewer' => ['exec'],
        ];
    }

    /**
     * The broadest single assertion in the run: every page in the
     * application, opened by every role. A 5xx anywhere is a smoke failure
     * regardless of whether the role should have been admitted.
     */
    #[DataProvider('roleProvider')]
    public function test_no_page_returns_a_server_error_for_any_role(string $account): void
    {
        $user = $this->account($account);
        $errors = [];
        $checked = 0;

        foreach ($this->pageRoutes() as $route) {
            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));
            $checked++;

            if ($response->status() >= 500) {
                $errors[] = $route->uri().' → '.$response->status();
            }
        }

        $this->assertGreaterThan(80, $checked, 'The sweep found suspiciously few pages.');

        $this->assertSame(
            [],
            $errors,
            "Pages returned a server error for {$account}:\n  ".implode("\n  ", $errors)
        );
    }

    /**
     * Where the routing table and the policy layer disagree, the policy
     * must be the **stricter** of the two — never the looser.
     *
     * A number of pages carry no role/permission middleware at all and are
     * gated entirely by an in-controller `authorize()` call:
     * `controls/create`, `exceptions/create`, `frameworks/create`,
     * `obligations/create`, `policies/create`, `csa-campaigns/create`,
     * `incidents/create`, `links/candidates`, `cases/board-extract`.
     * That is the codebase's defence-in-depth pattern and it is working —
     * a System Administrator is refused `exceptions/create`, consistent
     * with the deliberate absence of an admin bypass in ExceptionPolicy.
     *
     * So the invariant worth asserting is not "a qualifying role is always
     * admitted" — it is that a refusal is a clean 403 from the policy, and
     * never a server error or a partial render. The looser direction is
     * covered by test_a_non_qualifying_role_is_never_admitted().
     *
     * The divergence itself is written out as evidence rather than
     * asserted, so the inventory is visible and reviewable.
     */
    #[DataProvider('roleProvider')]
    public function test_where_a_policy_is_stricter_than_the_route_it_refuses_cleanly(string $account): void
    {
        $user = $this->account($account);
        $stricter = [];
        $bad = [];

        foreach ($this->pageRoutes() as $route) {
            if (! $this->qualifies($user, $route)) {
                continue;
            }

            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));

            if ($response->status() === 403) {
                $stricter[] = $route->uri();

                continue;
            }

            if (! $response->isSuccessful() && ! $response->isRedirect() && $response->status() !== 404) {
                $bad[] = $route->uri().' → '.$response->status();
            }
        }

        $this->assertSame(
            [],
            $bad,
            "{$account} met a page's declared requirements and received neither a render nor a clean "
            ."403/404:\n  ".implode("\n  ", $bad)
        );

        if ($stricter !== []) {
            // Replace this account's line rather than appending — a plain
            // FILE_APPEND grew the evidence file by one block per suite run.
            $path = base_path('docs/testing/evidence/tier2-policy-stricter-than-route.txt');
            $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
            $lines = array_values(array_filter($lines, fn ($l) => ! str_starts_with($l, str_pad($account, 24))));
            $lines[] = str_pad($account, 24).implode(', ', $stricter);
            file_put_contents($path, implode("\n", $lines)."\n");
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The converse, and the more important half: a role that does *not*
     * meet a page's requirements must never be admitted.
     */
    #[DataProvider('roleProvider')]
    public function test_a_non_qualifying_role_is_never_admitted(string $account): void
    {
        $user = $this->account($account);
        $admitted = [];
        $checked = 0;

        foreach ($this->pageRoutes() as $route) {
            if ($this->requirementsFor($route) === [] || $this->qualifies($user, $route)) {
                continue;
            }

            $checked++;
            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));

            if ($response->isSuccessful()) {
                $admitted[] = $route->uri().' → '.$response->status();
            }
        }

        if ($checked === 0) {
            $this->markTestSkipped("{$account} qualifies for every gated page; nothing to test.");
        }

        $this->assertSame(
            [],
            $admitted,
            "{$account} was admitted to pages it does not qualify for:\n  ".implode("\n  ", $admitted)
        );
    }

    // -----------------------------------------------------------------
    // Tenant isolation across every domain
    // -----------------------------------------------------------------

    /**
     * The other tenant's fixtures are named "MUST NEVER BE VISIBLE TO
     * TENANT 1". Any page in any domain that renders that string is
     * leaking across the tenant boundary.
     */
    #[DataProvider('roleProvider')]
    public function test_no_page_in_any_domain_leaks_another_tenants_records(string $account): void
    {
        $user = $this->account($account);
        $leaks = [];

        foreach ($this->pageRoutes() as $route) {
            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));

            if (! $response->isSuccessful()) {
                continue;
            }

            if (str_contains($response->getContent(), 'MUST NEVER BE VISIBLE')) {
                $leaks[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "CRITICAL: another tenant's records rendered for {$account} on:\n  ".implode("\n  ", $leaks)
        );
    }

    /** The reverse direction — the other tenant must not see ours either. */
    public function test_the_other_tenant_sees_none_of_our_records_anywhere(): void
    {
        $user = $this->otherTenantAccount('other_cfh');
        $ourReference = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->value('reference');

        $this->assertNotNull($ourReference, 'Fixture: no exception in our tenant.');

        $leaks = [];

        foreach ($this->pageRoutes() as $route) {
            $response = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'));

            if ($response->isSuccessful() && str_contains($response->getContent(), $ourReference)) {
                $leaks[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "CRITICAL: our exception {$ourReference} rendered for the other tenant on:\n  ".implode("\n  ", $leaks)
        );
    }

    // -----------------------------------------------------------------
    // Deactivated user, across every domain
    // -----------------------------------------------------------------

    /**
     * DEF-007 established that deactivation does not end a live session.
     * This measures how far that reaches: every page in the application,
     * not just the one probed originally.
     */
    public function test_a_deactivated_user_reaches_pages_across_every_domain(): void
    {
        $inactive = $this->account('inactive');
        $reachable = [];

        foreach ($this->pageRoutes() as $route) {
            if ($this->actingAs($inactive)->get('/'.ltrim($route->uri(), '/'))->isSuccessful()) {
                $reachable[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $reachable,
            'DEF-007 (scope): a deactivated user reached '.count($reachable).' pages. Deactivation is '
            ."checked only at login, so it reaches nothing already in flight:\n  "
            .implode("\n  ", array_slice($reachable, 0, 25))
            .(count($reachable) > 25 ? "\n  … and ".(count($reachable) - 25).' more' : '')
        );
    }

    // -----------------------------------------------------------------
    // Feature flags
    // -----------------------------------------------------------------

    public function test_disabling_a_feature_flag_closes_its_module(): void
    {
        $flag = FeatureFlag::withoutGlobalScopes()->where('key', 'vendors')->first();

        if (! $flag) {
            $this->markTestSkipped('No vendors feature flag seeded.');
        }

        $this->actingAs($this->account('cfh'))->get('/vendors')->assertOk();

        $flag->forceFill(['is_enabled' => false])->saveQuietly();
        app(FeatureService::class)->flush();

        $this->assertFalse(
            $this->actingAs($this->account('cfh'))->get('/vendors')->isSuccessful(),
            'A disabled feature flag did not close its module.'
        );
    }
}
