<?php

use App\Jobs\GenerateCalendarPostsJob;
use App\Jobs\GenerateMonthlyCalendarJob;
use App\Jobs\GenerateScheduledContentJob;
use App\Jobs\GenerateSmartSuggestionsJob;
use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\RetryFailedPostsJob;
use App\Jobs\RefreshSocialTokensJob;
use App\Jobs\SyncAnalyticsConnectionsJob;
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

// Gerar posts automaticos das pautas do calendario editorial - 1x por dia às 6h
Schedule::job(new GenerateCalendarPostsJob)->dailyAt('06:00')->withoutOverlapping(15);

// Gerar calendario mensal automatico com IA - dia 25 de cada mes às 8h
// Cria pautas com status 'draft' para aprovacao do usuario
Schedule::job(new GenerateMonthlyCalendarJob)->monthlyOn(25, '08:00')->withoutOverlapping(30);

/*
|--------------------------------------------------------------------------
| Social Insights - Coleta de Métricas
|--------------------------------------------------------------------------
|
| Sincronizar insights das contas sociais conectadas.
| Roda 2x ao dia (manhã e noite) para manter dados atualizados.
|
*/

// Sincronizar insights sociais - 2x por dia (8h e 20h)
Schedule::command('social:sync-insights --all')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('social:sync-insights --all')->dailyAt('20:00')->withoutOverlapping();

// Auto-sync metricas vinculadas a contas sociais - logo apos o sync de insights
Schedule::job(new \App\Jobs\SyncSocialMetricEntriesJob)->dailyAt('08:30')->withoutOverlapping();
Schedule::job(new \App\Jobs\SyncSocialMetricEntriesJob)->dailyAt('20:30')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Analytics - Sincronizacao de Dados
|--------------------------------------------------------------------------
|
| Sincronizar conexoes analytics (GA4, Google Ads, Search Console,
| Meta Ads, WooCommerce) automaticamente.
| Roda 3x por dia para manter dados atualizados.
|
*/

// Sincronizar todas as conexoes analytics - 3x por dia (6h, 12h, 18h)
Schedule::job(new SyncAnalyticsConnectionsJob)->dailyAt('06:00')->withoutOverlapping(30);
Schedule::job(new SyncAnalyticsConnectionsJob)->dailyAt('12:00')->withoutOverlapping(30);
Schedule::job(new SyncAnalyticsConnectionsJob)->dailyAt('18:00')->withoutOverlapping(30);

/*
|--------------------------------------------------------------------------
| Limpeza e Manutenção
|--------------------------------------------------------------------------
*/

// Limpar registros temporarios de OAuth discovery - 1x por dia
Schedule::call(fn() => \App\Models\OAuthDiscoveredAccount::cleanup())->daily();

// Limpar logs antigos (30+ dias) - 1x por semana
Schedule::call(fn() => \App\Models\SystemLog::cleanup(30))->weekly();

/*
|--------------------------------------------------------------------------
| Email Marketing - Tarefas Agendadas
|--------------------------------------------------------------------------
*/

// Gerar sugestões de email marketing com IA - 1x por dia às 7:30
Schedule::job(new \App\Jobs\GenerateEmailAiSuggestionsJob)->dailyAt('07:30')->withoutOverlapping(15);

// Sincronizar fontes externas de contatos (WooCommerce, MySQL, Sheets) - 1x por dia às 5h
Schedule::job(new \App\Jobs\SyncAllEmailListSourcesJob)->dailyAt('05:00')->withoutOverlapping(30);

// Processar campanhas agendadas - a cada minuto
Schedule::call(function () {
    $campaigns = \App\Models\EmailCampaign::readyToSend()->get();
    foreach ($campaigns as $campaign) {
        app(\App\Services\Email\EmailCampaignService::class)->startCampaign($campaign);
    }
})->name('email.process-scheduled')->everyMinute()->withoutOverlapping();

// Atualizar estatísticas de campanhas em andamento - a cada 5 minutos
Schedule::call(function () {
    \App\Models\EmailCampaign::where('status', 'sending')->each(fn($c) => $c->refreshStats());
})->name('email.refresh-stats')->everyFiveMinutes()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| SMS Marketing - Tarefas Agendadas
|--------------------------------------------------------------------------
*/

// Processar campanhas SMS agendadas - a cada minuto
Schedule::call(function () {
    $campaigns = \App\Models\SmsCampaign::readyToSend()->get();
    foreach ($campaigns as $campaign) {
        app(\App\Services\Sms\SmsCampaignService::class)->startCampaign($campaign);
    }
})->name('sms.process-scheduled')->everyMinute()->withoutOverlapping();

// Atualizar estatísticas de campanhas SMS em andamento - a cada 5 minutos
Schedule::call(function () {
    \App\Models\SmsCampaign::where('status', 'sending')->each(fn($c) => $c->refreshStats());
})->name('sms.refresh-stats')->everyFiveMinutes()->withoutOverlapping();

// Gerar sugestões de SMS marketing com IA - 1x por dia às 8h
Schedule::job(new \App\Jobs\GenerateSmsAiSuggestionsJob)->dailyAt('08:00')->withoutOverlapping(15);
