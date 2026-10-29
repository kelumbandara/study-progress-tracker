<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Exchanges e-mail + password for a long-lived bearer token for this device. */
    public function login(Request $request): JsonResponse
    {
        $d = $request->validate([
            'email' => ['required', 'string', 'email'], 'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $key = 'api-login|' . Str::transliterate(Str::lower($d['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Please try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $user = User::where('email', Str::lower($d['email']))->first();
        // Same message for an unknown e-mail and a wrong password; check the hash either way.
        if (! $user || ! Hash::check($d['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }
        RateLimiter::clear($key);

        // One token per device name: signing in again on the same phone replaces the old token instead of piling up.
        $user->tokens()->where('name', $d['device_name'])->delete();
        $expires = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken($d['device_name'], ['*'], $expires);

        return response()->json([
            'token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(),
            'user' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['user' => ['name' => $u->name, 'email' => $u->email]]);
    }
}
