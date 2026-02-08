<?php

use App\Jobs\GenerateScheduledContentJob;
use App\Jobs\GenerateSmartSuggestionsJob;
use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\RetryFailedPostsJob;
use App\Jobs\RefreshSocialTokensJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Autopilot - Tarefas Agendadas
|--------------------------------------------------------------------------
|
| Processar posts agendados, re-tentar falhas e renovar tokens.
| O scheduler roda a cada minuto via Docker (mkt-privus-scheduler).
|
*/

// Processar posts agendados prontos para publicacao - a cada minuto
Schedule::job(new ProcessScheduledPostsJob)->everyMinute()->withoutOverlapping();

// Re-tentar posts que falharam (com tentativas restantes) - a cada 15 minutos
Schedule::job(new RetryFailedPostsJob)->everyFifteenMinutes()->withoutOverlapping();

// Renovar tokens de contas sociais prestes a expirar - a cada hora
Schedule::job(new RefreshSocialTokensJob)->hourly()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Content Engine - Geração Automática de Conteúdo
|--------------------------------------------------------------------------
|
| Gerar conteúdo baseado em pautas configuradas e sugestões inteligentes.
|
*/

// Gerar conteúdo de pautas que estão prontas - a cada hora
Schedule::job(new GenerateScheduledContentJob)->hourly()->withoutOverlapping();

// Gerar sugestões inteligentes automaticas - 1x por dia às 7h
Schedule::job(new GenerateSmartSuggestionsJob)->dailyAt('07:00')->withoutOverlapping();
