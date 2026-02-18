<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Rota de storage SEM middleware web (sem session, sem CSP sandbox)
            // Necessário para APIs externas baixarem mídias via URL pública
            \Illuminate\Support\Facades\Route::middleware([])
                ->group(base_path('routes/storage.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\ShareBrandData::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'email/webhook/*',
            'email/t/*',
            'sms/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
