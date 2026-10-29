<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_website_is_in_english_until_a_language_is_chosen(): void
    {
        $this->get('/daily-log')->assertOk()->assertSee('Enter the day', false)->assertSee('lang="en"', false);
    }

    public function test_choosing_sinhala_translates_the_page_and_is_remembered(): void
    {
        $this->get('/language/si')->assertRedirect()->assertCookie('locale', 'si');

        $this->get('/daily-log')->assertOk()
            ->assertSee('දෛනික සටහන', false)           // page title and menu
            ->assertSee('දිනයේ කාලය ඇතුළත් කරන්න', false)
            ->assertSee('lang="si"', false)
            ->assertDontSee('Enter the total time', false);
    }

    public function test_the_choice_is_also_kept_by_the_cookie_alone(): void
    {
        $this->withCookie('locale', 'si')->get('/theory')->assertOk()->assertSee('න්‍යාය අධ්‍යයනය', false);
    }

    public function test_switching_back_to_english_works(): void
    {
        $this->get('/language/si');
        $this->get('/language/en');
        $this->get('/daily-log')->assertSee('Enter the day', false)->assertDontSee('දිනයේ කාලය', false);
    }

    public function test_an_unknown_language_is_refused_and_nothing_changes(): void
    {
        $this->get('/language/xx')->assertNotFound();
        $this->get('/language/<script>')->assertNotFound();
        $this->get('/daily-log')->assertSee('lang="en"', false);
    }

    public function test_the_switch_never_redirects_to_another_site(): void
    {
        $this->from('https://evil.example/phish')->get('/language/si')->assertRedirect(url('/'));
        $this->from(url('/theory'))->get('/language/si')->assertRedirect(url('/theory'));
    }

    public function test_the_sign_in_page_can_be_switched_before_signing_in(): void
    {
        auth()->logout();
        $this->get('/language/si')->assertRedirect();
        $this->get('/login')->assertOk()->assertSee('පිවිසෙන්න', false)->assertSee('ඊමේල්', false);
    }

    public function test_the_api_stays_in_english_whatever_the_website_language_is(): void
    {
        $this->get('/language/si');
        $this->getJson('/api/v1/bootstrap')->assertUnauthorized();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_sinhala_validation_messages_are_used_on_the_website(): void
    {
        $this->get('/language/si');
        $this->post('/theory', [])->assertSessionHasErrors('topic_id');
        $msg = session('errors')->first('topic_id');
        $this->assertStringContainsString('අවශ්‍යයි', $msg);
    }
}
