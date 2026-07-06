<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Forcar HTTPS quando em producao (atras de proxy como Traefik/Coolify)
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Rate limiter para endpoints caros de IA/geração (custo direto de API).
        // 20 req/min por usuário evita abuso por script/cliques repetidos.
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(20)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        // Rate limiter para disparo de campanhas (e-mail/SMS).
        RateLimiter::for('campaign-send', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });
    }
}
