<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false; // no web session may help an API call

    private const PASSWORD = 'correct horse 42 battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));
    }

    private function user(): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@example.com', 'password' => Hash::make(self::PASSWORD)]);
    }

    private function login(string $device = 'Pixel 7', array $over = [])
    {
        return $this->postJson('/api/v1/auth/login', array_merge(['email' => 'student@example.com', 'password' => self::PASSWORD, 'device_name' => $device], $over));
    }

    // ---- signing in ------------------------------------------------------------------------

    public function test_login_returns_a_bearer_token_that_works_and_is_stored_hashed(): void
    {
        $this->user();
        $r = $this->login()->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['name', 'email']])->assertJson(['token_type' => 'Bearer']);
        $plain = $r->json('token');

        $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJson(['user' => ['email' => 'student@example.com', 'name' => 'Student']]);

        $row = PersonalAccessToken::first();
        $this->assertNotSame($plain, $row->token);               // only a hash is kept
        $this->assertSame('Pixel 7', $row->name);
        $this->assertEqualsWithDelta(365 * 24 * 3600, $row->expires_at->diffInSeconds(now(), true), 120);
    }

    public function test_wrong_password_and_unknown_email_give_the_same_message(): void
    {
        $this->user();
        $a = $this->login('Pixel 7', ['password' => 'nope nope nope nope']);
        $b = $this->login('Pixel 7', ['email' => 'nobody@example.com']);
        $a->assertStatus(422)->assertJsonValidationErrors(['email']);
        $b->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertSame($a->json('errors.email'), $b->json('errors.email'));
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_is_rate_limited_after_five_failures_then_recovers(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->login('Pixel 7', ['password' => 'wrong wrong wrong ' . $i])->assertStatus(422);
        }
        $r = $this->login()->assertStatus(422);                    // even the right password is refused for now
        $this->assertStringContainsString('Too many sign-in attempts', $r->json('errors.email.0'));
        $this->assertSame(0, PersonalAccessToken::count());

        $this->travel(2)->minutes();
        $this->login()->assertOk();
    }

    public function test_login_validates_its_input(): void
    {
        $this->user();
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'device_name']);
        $this->login('', [])->assertStatus(422)->assertJsonValidationErrors(['device_name']);
        $this->login(str_repeat('x', 101))->assertStatus(422)->assertJsonValidationErrors(['device_name']);
        $this->login('Pixel', ['email' => ['a@b.c'], 'password' => ['x']])->assertStatus(422);
    }

    public function test_signing_in_again_on_the_same_device_replaces_its_token_but_other_devices_keep_theirs(): void
    {
        $this->user();
        $first = $this->login('Pixel 7')->json('token');
        $tablet = $this->login('Tablet')->json('token');
        $second = $this->login('Pixel 7')->json('token');

        $this->assertSame(2, PersonalAccessToken::count());
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    }

    // ---- ending a session ------------------------------------------------------------------

    public function test_logout_revokes_only_this_devices_token(): void
    {
        $this->user();
        $phone = $this->login('Pixel 7')->json('token');
        $tablet = $this->login('Tablet')->json('token');

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_changing_the_password_on_the_website_signs_every_phone_out(): void
    {
        $u = $this->user();
        $token = $this->login()->json('token');
        $this->actingAs($u)->put('/account/password', ['current_password' => self::PASSWORD, 'password' => 'brand new pass 777', 'password_confirmation' => 'brand new pass 777'])->assertSessionHasNoErrors();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_server_side_password_reset_signs_every_phone_out(): void
    {
        $this->user();
        $token = $this->login()->json('token');
        $this->artisan('tracker:set-password', ['email' => 'student@example.com', '--password' => 'a fresh long pass 55'])->assertSuccessful();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->user();
        $token = $this->login()->json('token');
        $this->travel(366)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    // ---- everything is protected -----------------------------------------------------------

    public function test_every_api_route_except_login_requires_a_token(): void
    {
        $checked = 0;
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/auth/login') {
                continue;
            }
            $uri = preg_replace(['/\{date\}/', '/\{[^}]+\}/'], ['2026-11-09', '1'], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $r = $this->json($method, '/' . $uri);
                $this->assertSame(401, $r->getStatusCode(), "{$method} /{$uri} was reachable without a token (HTTP {$r->getStatusCode()})");
                $r->assertJson(['message' => 'Unauthenticated.']);
                $checked++;
            }
        }
        $this->assertGreaterThan(25, $checked);
    }

    public function test_a_garbage_or_malformed_token_is_refused(): void
    {
        foreach (['', 'abc', '1|', '999|nonsense', str_repeat('x', 500)] as $t) {
            $this->withHeader('Authorization', 'Bearer ' . $t)->getJson('/api/v1/me')->assertUnauthorized();
        }
        $this->withHeader('Authorization', 'Basic ' . base64_encode('student@example.com:' . self::PASSWORD))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_website_session_cannot_call_the_api(): void
    {
        $u = $this->user();
        $this->actingAs($u)->getJson('/api/v1/me')->assertUnauthorized();   // signed in on the website, but no token
        $this->actingAs($u)->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_the_api_sets_no_cookies_and_needs_no_csrf_token(): void
    {
        $this->user();
        $r = $this->login()->assertOk();
        $this->assertEmpty($r->headers->getCookies());
        $this->assertNull($r->headers->get('Set-Cookie'));
    }

    // ---- JSON everywhere -------------------------------------------------------------------

    public function test_errors_are_json_even_without_an_accept_header(): void
    {
        $this->user();
        $token = $this->login()->json('token');
        $this->app['auth']->forgetGuards();

        $r = $this->withToken($token)->withHeader('Accept', 'text/html')->get('/api/v1/papers/99999/questions');
        $r->assertNotFound();
        $this->assertStringStartsWith('application/json', $r->headers->get('Content-Type'));

        $this->flushHeaders();                 // the token set above would otherwise stay on later requests
        $this->app['auth']->forgetGuards();
        $r = $this->withHeader('Accept', 'text/html')->get('/api/v1/dashboard');
        $r->assertUnauthorized();
        $this->assertStringStartsWith('application/json', $r->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', $r->getContent());

        $r = $this->withHeader('Accept', 'text/html')->get('/api/v1/no-such-endpoint');
        $r->assertNotFound();
        $this->assertStringStartsWith('application/json', $r->headers->get('Content-Type'));
    }

    public function test_security_headers_are_on_api_responses_and_throttle_headers_are_present(): void
    {
        $this->user();
        $token = $this->login()->json('token');
        $this->app['auth']->forgetGuards();
        $r = $this->withToken($token)->getJson('/api/v1/me');
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($r->headers->get('X-RateLimit-Limit'));
    }

    public function test_the_csrf_cookie_route_for_browser_apps_is_not_exposed(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }
}
