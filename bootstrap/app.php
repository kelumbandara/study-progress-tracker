<?php

use App\Http\Middleware\ForceJson;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // the phone app's token API, under /api/v1
        commands: __DIR__.'/../routes/console.php',
        health: '/up',   // used by the hosting platform's health check; shows no data
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Coolify's reverse proxy the request arrives as plain HTTP; trust its forwarded headers
        // so the app knows the real scheme/host. Restrict with TRUSTED_PROXIES if the container is ever exposed directly.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->web(append: [SetLocale::class, SecurityHeaders::class]);
        $middleware->api(prepend: [ForceJson::class], append: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Any URL under /api/ gets JSON errors, even when the client forgot the Accept header.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
