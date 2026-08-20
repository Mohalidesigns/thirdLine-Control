<?php

namespace Tests\e2e;

use App\Models\User;
use Database\Seeders\E2ETestSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part B §10 — application-level security checks.
 *
 * Header, cookie, debug-mode and directory-exposure checks were run
 * against the **live** application because they are properties of a real
 * HTTP response — see
 * `docs/testing/evidence/S10-security-live-verification.txt`. This file
 * covers the checks better made in code: hashing, auth throttling,
 * session fixation, open redirect and secret exposure.
 */
class SecurityBaselineTest extends E2ETestCase
{
    // -----------------------------------------------------------------
    // Password storage
    // -----------------------------------------------------------------

    public function test_passwords_are_stored_with_a_modern_adaptive_hash(): void
    {
        $hash = User::withoutGlobalScopes()->where('email', self::ACCOUNTS['officer'])->value('password');

        $this->assertNotEmpty($hash);
        $info = password_get_info($hash);

        $this->assertContains(
            $info['algoName'],
            ['bcrypt', 'argon2i', 'argon2id'],
            'Passwords must use an adaptive hash. Found: '.$info['algoName']
        );

        $this->assertStringNotContainsString('password', $hash, 'The password appears in its own hash.');
        $this->assertTrue(Hash::check('password', $hash), 'Fixture: the seeded password does not verify.');
    }

    public function test_the_password_hash_is_never_exposed_through_the_ui(): void
    {
        $response = $this->actingAs($this->account('admin'))->get(route('admin.roles'));
        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('$2y$', $body, 'A bcrypt hash was serialised into a page payload.');
        $this->assertStringNotContainsString('remember_token', $body, 'The remember token was serialised into a page payload.');
    }

    // -----------------------------------------------------------------
    // Authentication throttling and session handling
    // -----------------------------------------------------------------

    public function test_repeated_failed_logins_are_throttled(): void
    {
        $email = self::ACCOUNTS['officer'];
        $statuses = [];

        for ($i = 0; $i < 8; $i++) {
            $this->flushSession();
            $this->withoutExceptionHandling();

            try {
                $this->post(route('login'), ['email' => $email, 'password' => 'wrong-'.$i]);
                $statuses[] = 'no-error';
            } catch (ValidationException $e) {
                $statuses[] = $e->errors()['email'][0] ?? '';
            } finally {
                $this->withExceptionHandling();
            }
        }

        $throttled = collect($statuses)->contains(fn ($m) => str_contains(strtolower((string) $m), 'seconds'));

        $this->assertTrue(
            $throttled,
            'FR-12.8: eight consecutive failed logins produced no lockout message. Attempts recorded: '
            .json_encode($statuses)
        );

        $this->assertGuest('web');
    }

    public function test_the_session_id_is_regenerated_on_login(): void
    {
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), [
            'email' => self::ACCOUNTS['officer'],
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame(
            $before,
            session()->getId(),
            'Session fixation: the session identifier survived authentication, so an attacker who fixes '
            .'a victim\'s session id before login holds an authenticated session afterwards.'
        );
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->post(route('login'), ['email' => self::ACCOUNTS['officer'], 'password' => 'password']);
        $this->assertAuthenticated();

        $this->post(route('logout'));

        $this->assertGuest('web');

        $this->get(route('exceptions.index'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Open redirect
    // -----------------------------------------------------------------

    public static function openRedirectProvider(): array
    {
        return [
            'absolute external' => ['https://evil.example.com/harvest'],
            'protocol relative' => ['//evil.example.com'],
            'backslash trick' => ['https:/\\evil.example.com'],
        ];
    }

    #[DataProvider('openRedirectProvider')]
    public function test_login_does_not_redirect_to_an_external_destination(string $target): void
    {
        $this->get(route('login').'?redirect='.urlencode($target));

        $response = $this->post(route('login'), [
            'email' => self::ACCOUNTS['officer'],
            'password' => 'password',
            'redirect' => $target,
        ]);

        $location = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString(
            'evil.example.com',
            $location,
            "Open redirect: authentication redirected to {$target}."
        );
    }

    // -----------------------------------------------------------------
    // Secret exposure
    // -----------------------------------------------------------------

    public function test_no_secret_reaches_the_client_bundle(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            $this->markTestSkipped('No built assets present.');
        }

        $leaks = [];

        foreach (json_decode(file_get_contents($manifest), true) as $entry) {
            $file = public_path('build/'.$entry['file']);

            if (! file_exists($file) || ! str_ends_with($file, '.js')) {
                continue;
            }

            $contents = file_get_contents($file);

            foreach (['APP_KEY', 'base64:', 'DB_PASSWORD', 'MAIL_PASSWORD', 'AWS_SECRET'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $leaks[] = $entry['file'].' contains '.$needle;
                }
            }
        }

        $this->assertSame([], $leaks, "Secrets found in the client bundle:\n  ".implode("\n  ", $leaks));
    }

    public function test_the_application_key_is_set_and_not_the_framework_default(): void
    {
        $key = config('app.key');

        $this->assertNotEmpty($key, 'APP_KEY is not set.');
        $this->assertNotSame('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', $key);
        $this->assertGreaterThan(30, strlen($key));
    }

    // -----------------------------------------------------------------
    // Error verbosity
    // -----------------------------------------------------------------

    public function test_a_server_error_does_not_leak_internals_to_an_api_caller(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => E2ETestSeeder::THIRDLINE_API_KEY])
            ->getJson('/api/v1/controls?since='.urlencode("' OR 1=1--"));

        $this->assertLessThan(500, $response->status(), 'An injected filter produced a server error.');

        foreach (['SQLSTATE', 'vendor/laravel', 'Stack trace', '#0 /'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent(), "Response leaked '{$leak}'.");
        }
    }

    // -----------------------------------------------------------------
    // Configuration posture
    // -----------------------------------------------------------------

    /**
     * `APP_DEBUG` at runtime is a property of a deployed environment, not
     * of the test harness — this suite inherits it from `.env`. It was
     * verified on the running application instead
     * (`evidence/S10-security-live-verification.txt`: a request to an
     * unknown route returns a plain "Not Found" page with no stack trace).
     *
     * What is checkable here is what the repository *ships*, since that is
     * what an operator copies on first deploy.
     */
    public function test_the_shipped_environment_template_does_not_default_to_debug_in_production(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_ENV=local$/m', $example, '.env.example should default to local.');

        // DEF-020 remediation: the template now ships APP_DEBUG=false, so a
        // copied-verbatim .env fails safe rather than serving stack traces.
        $this->assertMatchesRegularExpression(
            '/^APP_DEBUG=false$/m',
            $example,
            'DEF-020: .env.example must default APP_DEBUG to false — an operator copying the template '
            .'verbatim must not run production with verbose errors enabled.'
        );

        // And the deployment guidance must still say to keep it off.
        $guidance = collect(glob(base_path('docs/deployment/*')))
            ->merge([base_path('SECURITY.md')])
            ->filter(fn ($f) => is_file($f))
            ->map(fn ($f) => file_get_contents($f))
            ->implode("\n");

        $this->assertStringContainsString(
            'APP_DEBUG',
            $guidance,
            'The repository ships APP_DEBUG=true in .env.example and no deployment or security document '
            .'mentions APP_DEBUG at all. An operator following the documented setup would run production '
            .'with verbose errors enabled, serving a full stack trace — including file paths, SQL and '
            .'configuration — to any caller who triggers an exception.'
        );
    }
}
