<?php

namespace Tests\Feature;

use App\Models\PhoneAppUsage;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneUsageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->today = CarbonImmutable::now('Asia/Colombo')->toDateString();
    }

    private function day(string $date, array $apps)
    {
        return $this->putJson("/api/v1/phone-usage/{$date}", ['apps' => $apps]);
    }

    private function app(string $package, string $label, int $minutes): array
    {
        return ['package' => $package, 'label' => $label, 'minutes' => $minutes];
    }

    public function test_the_phone_sends_a_day_and_it_is_stored_with_a_kind_per_app(): void
    {
        $this->day($this->today, [
            $this->app('com.whatsapp', 'WhatsApp', 45), $this->app('com.facebook.katana', 'Facebook', 60),
            $this->app('com.google.android.youtube', 'YouTube', 40), $this->app('com.example.unknown', 'Mystery', 5),
        ])->assertOk()->assertJson(['apps_stored' => 4, 'total_minutes' => 150]);

        $cats = PhoneAppUsage::pluck('category', 'package')->all();
        $this->assertSame(['com.whatsapp' => 'messaging', 'com.facebook.katana' => 'social', 'com.google.android.youtube' => 'video', 'com.example.unknown' => 'other'], $cats);
    }

    public function test_sending_the_same_day_again_replaces_it_and_never_doubles(): void
    {
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 30), $this->app('com.facebook.katana', 'Facebook', 20)])->assertOk();
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 45)])->assertOk()->assertJson(['total_minutes' => 45]);

        $this->assertSame(1, PhoneAppUsage::count());
        $this->assertSame(45, PhoneAppUsage::first()->minutes);
    }

    public function test_zero_minute_apps_are_not_stored_and_an_empty_day_is_accepted(): void
    {
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 0)])->assertOk()->assertJson(['apps_stored' => 0]);
        $this->assertSame(0, PhoneAppUsage::count());
    }

    public function test_bad_input_is_refused_and_nothing_is_stored(): void
    {
        $tomorrow = CarbonImmutable::parse($this->today)->addDay()->toDateString();
        $this->day($tomorrow, [])->assertStatus(422);
        $this->day(CarbonImmutable::parse($this->today)->subDays(40)->toDateString(), [])->assertStatus(422);
        $this->day('2026-02-30', [])->assertStatus(422);
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 1441)])->assertStatus(422);
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', -3)])->assertStatus(422);
        $this->day($this->today, [$this->app('bad package!', 'X', 5)])->assertStatus(422);
        $this->putJson("/api/v1/phone-usage/{$this->today}", [])->assertStatus(422);   // "apps" must be present
        $this->assertSame(0, PhoneAppUsage::count());
    }

    public function test_app_names_cannot_carry_control_characters_or_html_onto_the_website(): void
    {
        $this->day($this->today, [$this->app('com.evil', "Bad\x00\x07Name <script>alert(1)</script>", 10)])->assertOk();
        $label = PhoneAppUsage::first()->label;
        $this->assertStringNotContainsString("\x00", $label);

        $this->actingAs((new User())->forceFill(['id' => 1, 'name' => 'T', 'email' => 't@example.com']));
        $this->get('/phone-usage')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_the_summary_shows_missing_days_as_no_data_not_zero(): void
    {
        $yesterday = CarbonImmutable::parse($this->today)->subDay()->toDateString();
        $this->day($yesterday, [$this->app('com.whatsapp', 'WhatsApp', 60)])->assertOk();

        $r = $this->getJson('/api/v1/phone-usage?days=3')->assertOk()->json();
        $this->assertSame(1, $r['days_with_data']);
        $this->assertSame(60, $r['avg_per_logged_day']);          // averaged over reported days only
        $byDate = collect($r['days'])->keyBy('date');
        $this->assertNull($byDate[$this->today]['total_minutes']);   // not reported: null, not 0
        $this->assertSame(60, $byDate[$yesterday]['total_minutes']);
        $this->assertSame('WhatsApp', $r['top_apps'][0]['label']);
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->app->get('auth')->forgetGuards();
        $this->putJson("/api/v1/phone-usage/{$this->today}", ['apps' => []])->assertUnauthorized();
        $this->getJson('/api/v1/phone-usage')->assertUnauthorized();
        $this->deleteJson('/api/v1/phone-usage')->assertUnauthorized();
    }

    public function test_the_student_can_erase_everything_from_the_phone_and_from_the_website(): void
    {
        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 30)])->assertOk();
        $this->deleteJson('/api/v1/phone-usage')->assertOk()->assertJson(['deleted_rows' => 1]);
        $this->assertSame(0, PhoneAppUsage::count());

        $this->day($this->today, [$this->app('com.whatsapp', 'WhatsApp', 30)])->assertOk();
        $this->actingAs((new User())->forceFill(['id' => 1, 'name' => 'T', 'email' => 't@example.com']));
        $this->delete('/phone-usage')->assertSessionHasErrors('confirm');   // needs the explicit confirmation
        $this->assertSame(1, PhoneAppUsage::count());
        $this->delete('/phone-usage', ['confirm' => 'erase'])->assertRedirect(route('phone-usage.index'));
        $this->assertSame(0, PhoneAppUsage::count());
    }

    public function test_the_website_shows_the_page_and_the_dashboard_card_in_both_languages(): void
    {
        $this->actingAs((new User())->forceFill(['id' => 1, 'name' => 'T', 'email' => 't@example.com']));
        $this->get('/phone-usage')->assertOk()->assertSee('No phone use reported yet.');
        $this->get('/')->assertOk()->assertSee('Phone use (last 7 days)');

        $student = StudentProfile::current();
        PhoneAppUsage::create(['student_id' => $student->id, 'usage_date' => $this->today, 'package' => 'com.whatsapp', 'label' => 'WhatsApp', 'minutes' => 45, 'category' => 'messaging']);
        $this->get('/phone-usage')->assertOk()->assertSee('WhatsApp')->assertSee('0 h 45 min')->assertSee('Messaging');
        $this->get('/')->assertOk()->assertSee('WhatsApp');

        $this->get('/language/si');
        $this->get('/phone-usage')->assertOk()->assertSee('දුරකථන භාවිතය', false)->assertSee('පණිවිඩ යෙදුම්', false)->assertDontSee('Share of phone time');
    }

    public function test_the_website_page_is_closed_when_signed_out(): void
    {
        $this->app->get('auth')->forgetGuards();
        $this->get('/phone-usage')->assertRedirect('/login');
        $this->delete('/phone-usage')->assertRedirect('/login');
    }
}
