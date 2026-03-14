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

// Renovar tokens de contas sociais prestes a expirar - a cada 15 min (Google/YouTube expira em 1h)
Schedule::job(new RefreshSocialTokensJob)->everyFifteenMinutes()->withoutOverlapping();

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

// Sincronizar métricas individuais de posts publicados - 3x por dia
Schedule::job(new \App\Jobs\SyncPostMetricsJob)->dailyAt('09:00')->withoutOverlapping(10);
Schedule::job(new \App\Jobs\SyncPostMetricsJob)->dailyAt('15:00')->withoutOverlapping(10);
Schedule::job(new \App\Jobs\SyncPostMetricsJob)->dailyAt('21:00')->withoutOverlapping(10);

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
    \App\Models\EmailCampaign::where('status', 'sending')->each(function ($campaign) {
        $campaign->refreshStats();

        // Detectar campanha concluída: todos processados e sem jobs pendentes
        $queued    = $campaign->events()->where('event_type', 'queued')->distinct('email_contact_id')->count('email_contact_id');
        $processed = $campaign->events()->whereIn('event_type', ['sent', 'failed'])->distinct('email_contact_id')->count('email_contact_id');

        if ($queued > 0 && $processed >= $queued) {
            $campaign->update(['status' => 'sent', 'completed_at' => now()]);
            \App\Models\SystemLog::info('email', 'campaign.auto_completed', "Campanha concluída automaticamente pelo scheduler", [
                'campaign_id' => $campaign->id,
                'sent'        => $campaign->fresh()->total_sent,
            ]);
            return;
        }

        // Detectar campanha travada: sem jobs na fila e sem atividade há mais de 30 min
        if ($campaign->started_at && $campaign->started_at->lt(now()->subMinutes(30))) {
            $jobsNaFila = \Illuminate\Support\Facades\DB::table('jobs')
                ->where('queue', 'email')
                ->where('payload', 'like', "%\"campaignId\":{$campaign->id}%")
                ->where('available_at', '<=', now()->addMinutes(5)->timestamp)
                ->count();

            $ultimoEvento = $campaign->events()->latest('occurred_at')->first();
            $minutesSemAtividade = $ultimoEvento
                ? now()->diffInMinutes($ultimoEvento->occurred_at)
                : 9999;

            if ($jobsNaFila === 0 && $minutesSemAtividade > 30 && $processed < $queued) {
                \App\Models\SystemLog::error('email', 'campaign.stuck_detected', "Campanha TRAVADA detectada pelo scheduler", [
                    'campaign_id'          => $campaign->id,
                    'campaign_name'        => $campaign->name,
                    'queued'               => $queued,
                    'processed'            => $processed,
                    'remaining'            => $queued - $processed,
                    'minutes_sem_atividade' => $minutesSemAtividade,
                    'jobs_na_fila'         => $jobsNaFila,
                ]);
            }
        }
    });
})->name('email.refresh-stats')->everyFiveMinutes()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| SMS Marketing - Tarefas Agendadas
|--------------------------------------------------------------------------
*/

// Processar campanhas SMS agendadas - a cada minuto
Schedule::call(function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('sms_campaigns')) return;
    $campaigns = \App\Models\SmsCampaign::readyToSend()->get();
    foreach ($campaigns as $campaign) {
        app(\App\Services\Sms\SmsCampaignService::class)->startCampaign($campaign);
    }
})->name('sms.process-scheduled')->everyMinute()->withoutOverlapping();

// Atualizar estatísticas de campanhas SMS em andamento - a cada 5 minutos
Schedule::call(function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('sms_campaigns')) return;
    \App\Models\SmsCampaign::where('status', 'sending')->each(fn($c) => $c->refreshStats());
})->name('sms.refresh-stats')->everyFiveMinutes()->withoutOverlapping();

// Gerar sugestões de SMS marketing com IA - 1x por dia às 8h
Schedule::call(function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('sms_campaigns')) return;
    dispatch(new \App\Jobs\GenerateSmsAiSuggestionsJob);
})->name('sms.ai-suggestions')->dailyAt('08:00')->withoutOverlapping(15);

/*
|--------------------------------------------------------------------------
| Blog - Geração Automática de Artigos
|--------------------------------------------------------------------------
*/

// Gerar artigos de blog com IA - 1x por dia às 9h
Schedule::job(new \App\Jobs\GenerateBlogArticlesJob)->dailyAt('09:00')->withoutOverlapping(30);

// Autopilot de blog: gera pautas + artigos semanalmente (segunda às 07h)
Schedule::job(new \App\Jobs\GenerateBlogAutopilotJob)->weeklyOn(1, '07:00')->withoutOverlapping(120);

// Publicar artigos agendados - a cada 5 minutos
Schedule::call(function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('blog_articles')) return;
    $articles = \App\Models\BlogArticle::readyToPublish()->get();
    $wpService = app(\App\Services\Blog\WordPressPublishService::class);
    foreach ($articles as $article) {
        $wpService->publish($article);
    }
})->name('blog.publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
