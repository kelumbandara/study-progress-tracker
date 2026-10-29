<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * One shared account. There is deliberately no registration page: the account is created
 * from the server with `php artisan tracker:create-user`.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']]);
        $key = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['email' => "Too many sign-in attempts. Please try again in {$seconds} seconds."]);
        }

        // "Keep me signed in" is on by default so each device only logs in once (about 13 months).
        if (! Auth::attempt($credentials, $request->boolean('remember', true))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('These details do not match our records.')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('You have been signed out.'));
    }

    public function editPassword()
    {
        return view('auth.password');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()],
        ], ['current_password.current_password' => __('The current password is not correct.')]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        Auth::logoutOtherDevices($data['password']); // a changed password signs out every other browser...
        $user->tokens()->delete();                    // ...and every phone
        $request->session()->regenerate();

        return redirect()->route('account.password')->with('status', __('Password changed. Other devices have been signed out.'));
    }
}
