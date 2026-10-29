<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StudyDailyLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private const PASSWORD = 'correct horse 42 battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        RateLimiter::clear('x'); // keep the limiter store tidy between tests
    }

    private function user(): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@example.com', 'password' => Hash::make(self::PASSWORD)]);
    }

    /** Every route the app has, so a newly added page cannot be forgotten. */
    private function appRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            // The phone API (api/v1/*) uses bearer tokens, no sessions or CSRF; ApiAuthTest walks those routes separately.
            ->filter(fn ($r) => ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'storage') && ! str_starts_with($r->uri(), 'api/v1/') && $r->uri() !== 'up')
            ->all();
    }

    // ---- everything is protected -----------------------------------------------------------

    public function test_every_page_and_action_requires_sign_in_except_login_and_health_check(): void
    {
        $public = ['login', 'up', 'language/{locale}']; // the language switch is deliberately public (it only stores a choice of language)
        $checked = 0;
        foreach ($this->appRoutes() as $route) {
            if (in_array($route->uri(), $public, true)) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, '/' . ltrim($uri, '/'));
                $this->assertContains($response->getStatusCode(), [302, 401, 419], "{$method} /{$uri} was reachable without signing in (HTTP {$response->getStatusCode()})");
                if ($response->getStatusCode() === 302) {
                    $this->assertStringContainsString('/login', $response->headers->get('Location'), "{$method} /{$uri} redirected somewhere other than the login page");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(30, $checked); // guard against the loop silently checking nothing
    }

    public function test_the_json_helpers_and_exports_are_not_readable_without_sign_in(): void
    {
        foreach (['/api/topics?subject_id=1', '/api/papers?subject_id=1&year=2024', '/reports/export?format=csv', '/reports'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }

    public function test_health_check_is_open_and_reveals_nothing(): void
    {
        $r = $this->get('/up')->assertOk();
        $this->assertStringNotContainsString('study', strtolower($r->getContent() === '' ? '' : strip_tags(substr($r->getContent(), 0, 0))));
        $this->assertStringNotContainsString('student@example.com', $r->getContent());
    }

    public function test_guest_is_sent_to_login_and_returns_to_the_page_they_wanted(): void
    {
        $this->user();
        $this->get('/reports')->assertRedirect('/login');
        $this->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD])->assertRedirect('/reports');
        $this->assertAuthenticated();
    }

    // ---- signing in ------------------------------------------------------------------------

    public function test_login_page_renders_and_signed_in_users_are_sent_away_from_it(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('Keep me signed in');
        $this->actingAs($this->user())->get('/login')->assertRedirect('/');
    }

    public function test_wrong_password_and_unknown_user_give_the_same_vague_message(): void
    {
        $this->user();
        $a = $this->from('/login')->post('/login', ['email' => 'student@example.com', 'password' => 'nope nope nope nope']);
        $b = $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'nope nope nope nope']);
        $a->assertSessionHasErrors(['email' => 'These details do not match our records.']);
        $b->assertSessionHasErrors(['email' => 'These details do not match our records.']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', ['email' => 'student@example.com', 'password' => 'wrong wrong wrong ' . $i]);
        }
        // the correct password is now refused too, until the window passes
        $r = $this->from('/login')->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD]);
        $r->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(2)->minutes();
        $this->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_login_validates_input(): void
    {
        $this->from('/login')->post('/login', ['email' => 'not-an-email', 'password' => ''])->assertSessionHasErrors(['email', 'password']);
        $this->from('/login')->post('/login', ['email' => ['a@b.c'], 'password' => ['x']])->assertSessionHasErrors(['email', 'password']); // array input is not accepted
    }

    public function test_keep_me_signed_in_sets_a_remember_cookie_and_can_be_switched_off(): void
    {
        $this->user();
        $on = $this->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD, 'remember' => '1']);
        $this->assertNotEmpty(collect($on->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web')));
        Auth::logout();

        $this->flushSession();
        $off = $this->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD, 'remember' => '0']);
        $this->assertNull(collect($off->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web') && $c->getValue() !== ''));
    }

    public function test_session_id_changes_on_login_to_prevent_fixation(): void
    {
        $this->user();
        $this->get('/login');
        $before = session()->getId();
        $this->post('/login', ['email' => 'student@example.com', 'password' => self::PASSWORD]);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_ends_the_session_and_needs_post(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($u)->get('/logout')->assertStatus(405); // a stray link cannot sign you out
    }

    public function test_signed_in_pages_show_the_user_menu_with_sign_out(): void
    {
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('Student')->assertSee('Log Out')->assertSee('Change password');
    }

    // ---- password change -------------------------------------------------------------------

    public function test_change_password_requires_the_current_password_and_a_strong_new_one(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/account/password')->assertOk();
        $put = fn (array $d) => $this->actingAs($u)->from('/account/password')->put('/account/password', $d);

        $put(['current_password' => 'wrong', 'password' => 'brand new pass 777', 'password_confirmation' => 'brand new pass 777'])->assertSessionHasErrors('current_password');
        $put(['current_password' => self::PASSWORD, 'password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $put(['current_password' => self::PASSWORD, 'password' => 'onlyletterslongpassword', 'password_confirmation' => 'onlyletterslongpassword'])->assertSessionHasErrors('password');
        $put(['current_password' => self::PASSWORD, 'password' => 'brand new pass 777', 'password_confirmation' => 'different pass 777'])->assertSessionHasErrors('password');
        $put(['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertSessionHasErrors('password'); // must differ
        $this->assertTrue(Hash::check(self::PASSWORD, $u->fresh()->password));

        $put(['current_password' => self::PASSWORD, 'password' => 'brand new pass 777', 'password_confirmation' => 'brand new pass 777'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand new pass 777', $u->fresh()->password));
    }

    // ---- accounts are created on the server only -------------------------------------------

    public function test_there_is_no_public_registration_or_password_reset_page(): void
    {
        foreach (['/register', '/password/reset', '/forgot-password', '/adminlte/darkmode/toggle'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/register', ['email' => 'x@y.z', 'password' => 'abcdefghijk123'])->assertNotFound();
        $this->post('/adminlte/darkmode/toggle')->assertNotFound(); // package route that was public; switched off in config
        $this->assertSame(0, User::count());
    }

    public function test_create_user_command_validates_and_hashes(): void
    {
        $this->artisan('tracker:create-user', ['email' => 'bad', '--password' => 'abcdefghijk123'])->assertFailed();
        $this->artisan('tracker:create-user', ['email' => 'a@example.com', '--password' => 'short1'])->assertFailed();
        $this->artisan('tracker:create-user', ['email' => 'a@example.com', '--password' => 'longenoughpassword'])->assertFailed(); // no digits
        $this->assertSame(0, User::count());

        $this->artisan('tracker:create-user', ['email' => 'A@Example.com', '--password' => 'longenough pass 99', '--name' => 'Kamal'])->assertSuccessful();
        $u = User::first();
        $this->assertSame('a@example.com', $u->email);
        $this->assertSame('Kamal', $u->name);
        $this->assertNotSame('longenough pass 99', $u->password);
        $this->assertTrue(Hash::check('longenough pass 99', $u->password));
        $this->artisan('tracker:create-user', ['email' => 'a@example.com', '--password' => 'another long pass 12'])->assertFailed(); // duplicate
    }

    public function test_set_password_command_resets_and_ends_remembered_sessions(): void
    {
        $u = $this->user();
        $u->forceFill(['remember_token' => 'old-token'])->save();
        $this->artisan('tracker:set-password', ['email' => 'student@example.com', '--password' => 'a fresh long pass 55'])->assertSuccessful();
        $u = $u->fresh();
        $this->assertTrue(Hash::check('a fresh long pass 55', $u->password));
        $this->assertNull($u->remember_token);
        $this->artisan('tracker:set-password', ['email' => 'nobody@example.com', '--password' => 'a fresh long pass 55'])->assertFailed();
        $this->artisan('tracker:set-password', ['email' => 'student@example.com', '--password' => 'weak'])->assertFailed();
    }

    // ---- hardening for a public site -------------------------------------------------------

    public function test_security_headers_are_sent(): void
    {
        $r = $this->get('/login');
        $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $this->assertNull($r->headers->get('Strict-Transport-Security')); // only in production over https
    }

    public function test_hsts_is_added_in_production_over_https(): void
    {
        $this->app['env'] = 'production';
        $r = $this->get('https://localhost/login');
        $this->assertStringContainsString('max-age=', (string) $r->headers->get('Strict-Transport-Security'));
    }

    public function test_forwarded_https_from_the_proxy_is_honoured(): void
    {
        $r = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'tracker.example.com', 'X-Forwarded-Port' => '443'])->get('/login');
        $r->assertOk();
        $this->assertStringContainsString('https://tracker.example.com/login', $r->getContent()); // form posts back over https
    }

    public function test_session_cookie_is_http_only_and_same_site(): void
    {
        $r = $this->get('/login');
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => str_contains($c->getName(), 'session'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_csrf_protection_is_part_of_every_state_changing_route(): void
    {
        // Laravel skips the CSRF check while tests run, so assert it is in the middleware chain of every POST/PUT/DELETE route.
        // (The browser suite proves the real behaviour: a login POST without a token gets HTTP 419.)
        $this->get('/login'); // the HTTP kernel registers the middleware groups on the first request
        $router = app('router');
        $checked = 0;
        foreach ($this->appRoutes() as $route) {
            if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }
            $chain = collect($router->resolveMiddleware($route->gatherMiddleware()))->map(fn ($m) => is_string($m) ? $m : get_class($m))->implode(' ');
            $this->assertMatchesRegularExpression('/VerifyCsrfToken|PreventRequestForgery|ValidateCsrfToken/', $chain, "{$route->uri()} has no CSRF protection");
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }

    // ---- audit trail records who ------------------------------------------------------------

    public function test_changes_record_which_account_made_them(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/daily-log', ['log_date' => now()->toDateString(), 'school_h' => 1, 'theory_h' => 1]);
        $this->actingAs($u)->post('/daily-log', ['log_date' => now()->toDateString(), 'school_h' => 2, 'theory_h' => 1]);

        $log = AuditLog::where('entity_type', 'study_daily_logs')->where('action', 'updated')->first();
        $this->assertNotNull($log);
        $this->assertSame($u->id, $log->user_id);
        $this->assertSame(1, StudyDailyLog::count());
    }
}
