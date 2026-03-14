<?php

namespace App\Jobs;

use App\Models\AnalyticsConnection;
use App\Models\BlogCalendarItem;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\Blog\BlogCalendarService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/**
 * Job de autopilot de blog.
 * Roda semanalmente: gera pautas para a próxima semana e, opcionalmente,
 * já gera os artigos completos (se require_approval = false).
 */
class GenerateBlogAutopilotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function handle(BlogCalendarService $calendarService): void
    {
        if (!Schema::hasTable('blog_articles') || !Schema::hasTable('blog_calendar_items')) {
            return;
        }

        SystemLog::info('blog', 'autopilot.start', 'Iniciando autopilot de blog');

        $brands = Brand::where('is_active', true)->get();
        $totalPautas    = 0;
        $totalArticles  = 0;

        foreach ($brands as $brand) {
            try {
                $cfg = $brand->getContentEngineConfig();

                // Autopilot desabilitado para esta marca
                if (empty($cfg['blog_autopilot_enabled'])) {
                    continue;
                }

                $postsPerWeek    = (int)   ($cfg['blog_posts_per_week']      ?? 2);
                $connectionId    = !empty($cfg['blog_connection_id']) ? (int) $cfg['blog_connection_id'] : null;
                $categoryId      = !empty($cfg['blog_category_id'])  ? (int) $cfg['blog_category_id']  : null;
                $requireApproval = (bool)  ($cfg['blog_require_approval']    ?? true);
                $tone            =          $cfg['blog_tone']                ?? ($brand->tone_of_voice ?? '');
                $instructions    =          $cfg['blog_instructions']        ?? '';
                $coverWidth      = (int)   ($cfg['blog_cover_width']         ?? 1750);
                $coverHeight     = (int)   ($cfg['blog_cover_height']        ?? 650);

                // Validar conexão
                if ($connectionId) {
                    $conn = AnalyticsConnection::find($connectionId);
                    if (!$conn || !$conn->is_active) {
                        SystemLog::warning('blog', 'autopilot.invalid_connection', "Conexão inválida para marca #{$brand->id}", [
                            'brand_id' => $brand->id, 'connection_id' => $connectionId,
                        ]);
                        continue;
                    }
                }

                // Período: próxima semana (segunda a domingo)
                $start = Carbon::now()->addDay()->startOfWeek(Carbon::MONDAY)->toDateString();
                $end   = Carbon::now()->addDay()->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();

                // Evitar gerar pautas duplicadas para o mesmo período
                $existing = BlogCalendarItem::where('brand_id', $brand->id)
                    ->whereBetween('scheduled_date', [$start, $end])
                    ->whereNull('article_id')
                    ->count();

                if ($existing >= $postsPerWeek) {
                    SystemLog::info('blog', 'autopilot.skip_period', "Pautas já existem para marca #{$brand->id} no período {$start} ~ {$end}", [
                        'brand_id' => $brand->id, 'existing' => $existing,
                    ]);
                    continue;
                }

                $userId = $brand->users()->first()?->id ?? 1;

                // 1. Gerar pautas com IA
                $calResult = $calendarService->generateCalendar(
                    brand: $brand,
                    userId: $userId,
                    startDate: $start,
                    endDate: $end,
                    options: [
                        'posts_per_week'         => $postsPerWeek,
                        'tone'                   => $tone,
                        'instructions'           => $instructions,
                        'wordpress_connection_id'=> $connectionId,
                        'blog_category_id'       => $categoryId,
                        'cover_width'            => $coverWidth,
                        'cover_height'           => $coverHeight,
                        // Se não requer aprovação: salvar já como approved
                        'batch_status'           => $requireApproval ? 'draft' : 'approved',
                    ],
                );

                $generated = $calResult['total'] ?? 0;
                $totalPautas += $generated;

                SystemLog::info('blog', 'autopilot.calendar_generated', "{$generated} pauta(s) geradas para marca #{$brand->id} ({$start} ~ {$end})", [
                    'brand_id' => $brand->id,
                    'batch_id' => $calResult['batch_id'] ?? null,
                    'require_approval' => $requireApproval,
                ]);

                // 2. Se não requer aprovação: gerar os artigos imediatamente
                if (!$requireApproval && $generated > 0) {
                    $artResult = $calendarService->generateArticlesForPendingItems(
                        brandId: $brand->id,
                        startDate: $start,
                        endDate: $end,
                        limit: $postsPerWeek + 2,
                    );

                    $articlesGenerated = $artResult['generated'] ?? 0;
                    $totalArticles += $articlesGenerated;

                    SystemLog::info('blog', 'autopilot.articles_generated', "{$articlesGenerated} artigo(s) gerados para marca #{$brand->id}", [
                        'brand_id' => $brand->id,
                    ]);
                }

            } catch (\Throwable $e) {
                SystemLog::error('blog', 'autopilot.error', "Erro no autopilot para marca #{$brand->id}: {$e->getMessage()}", [
                    'brand_id' => $brand->id,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        SystemLog::info('blog', 'autopilot.complete', "Autopilot concluído: {$totalPautas} pauta(s), {$totalArticles} artigo(s) gerados");
    }
}
