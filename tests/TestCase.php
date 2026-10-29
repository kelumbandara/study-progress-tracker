<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Most tests exercise the app as the signed-in shared account. Auth tests turn this off. */
    protected bool $signedIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->signedIn) {
            // An unsaved user is enough for the session guard, so tests that never touch the database still work.
            $this->actingAs((new User())->forceFill(['id' => 1, 'name' => 'Test', 'email' => 'test@example.com']));
        }
    }
}
