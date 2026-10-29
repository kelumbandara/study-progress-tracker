<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Website language: English or Sinhala, remembered in the session and in a cookie. The API and the phone app are not affected. */
class SetLocale
{
    public const LOCALES = ['en' => 'English', 'si' => 'සිංහල'];

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $request->session()->get('locale') ?? $request->cookie('locale');
        app()->setLocale(array_key_exists((string) $chosen, self::LOCALES) ? $chosen : config('app.locale'));

        return $next($request);
    }
}
