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

        $middleware->alias([
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'email/webhook/*',
            'email/t/*',
            'sms/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Inertia detecta-se pelo header X-Inertia. Para essas requests o
            // front espera REDIRECT com errors na sessão (que o Inertia
            // consome e expõe como `errors` reativo), não JSON. Devolver
            // JSON aqui quebra a tela com "All Inertia requests must receive
            // a valid Inertia response, however a plain JSON response was
            // received". Deixa o fluxo padrão do Laravel/Inertia converter
            // ValidationException em redirect automaticamente.
            if ($request->header('X-Inertia')) {
                return null;
            }

            // ValidationException tem formato próprio (422 + {message, errors})
            // que axios/fetch dos consumidores diretos do front (modais que
            // postam via axios puro, ex: social.generate-complete) já
            // entendem e renderizam por campo. Sobrescrever isso achatava o
            // array `errors` em uma string única ("The lists field is
            // required."), perdendo qual campo falhou. Deixa o Laravel cuidar.
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            if ($request->expectsJson() || $request->ajax()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                \Illuminate\Support\Facades\Log::error("Unhandled exception on {$request->method()} {$request->path()}: {$e->getMessage()}", [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return response()->json([
                    'error' => $e->getMessage() ?: 'Erro interno do servidor.',
                    'message' => $e->getMessage() ?: 'Erro interno do servidor.',
                ], $status);
            }
        });
    })->create();
