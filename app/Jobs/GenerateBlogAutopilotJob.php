<?php

namespace App\Jobs;

use App\Models\AnalyticsConnection;
use App\Models\BlogAutopilot;
use App\Models\BlogCalendarItem;
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
 * Job de autopilot de blog (múltiplos pilotos por marca).
 *
 * Sem argumento: roda TODOS os pilotos habilitados (chamada agendada).
 * Com $autopilotId: roda apenas aquele piloto (chamada manual via UI).
 *
 * Para cada piloto: gera pautas da próxima semana com as palavras-chave do
 * piloto somadas às da marca; se require_approval=false, já gera os artigos.
 */
class GenerateBlogAutopilotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(public readonly ?int $autopilotId = null) {}

    public function handle(BlogCalendarService $calendarService): void
    {
        if (!Schema::hasTable('blog_articles') || !Schema::hasTable('blog_calendar_items') || !Schema::hasTable('blog_autopilots')) {
            return;
        }

        SystemLog::info('blog', 'autopilot.start', $this->autopilotId
            ? "Iniciando autopilot de blog (piloto #{$this->autopilotId})"
            : 'Iniciando autopilot de blog (todos os pilotos habilitados)');

        $query = BlogAutopilot::with('brand')->enabled();
        if ($this->autopilotId !== null) {
            $query->where('id', $this->autopilotId);
        }

        $autopilots = $query->get();

        $totalPautas   = 0;
        $totalArticles = 0;

        foreach ($autopilots as $autopilot) {
            try {
                $brand = $autopilot->brand;
                if (!$brand || !$brand->is_active) {
                    continue;
                }

                // Validar conexão WordPress se houver
                $connectionId = $autopilot->connection_id;
                if ($connectionId) {
                    $conn = AnalyticsConnection::find($connectionId);
                    if (!$conn || !$conn->is_active) {
                        SystemLog::warning('blog', 'autopilot.invalid_connection', "Conexão inválida no autopilot #{$autopilot->id}", [
                            'autopilot_id'  => $autopilot->id,
                            'brand_id'      => $brand->id,
                            'connection_id' => $connectionId,
                        ]);
                        continue;
                    }
                }

                // Período: próxima semana (segunda a domingo)
                $start = Carbon::now()->addDay()->startOfWeek(Carbon::MONDAY)->toDateString();
                $end   = Carbon::now()->addDay()->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();

                // Evita duplicar pautas: olha as pautas pendentes da marca no período
                // (consideração simples — não diferencia entre pilotos, evita inundar).
                $existing = BlogCalendarItem::where('brand_id', $brand->id)
                    ->whereBetween('scheduled_date', [$start, $end])
                    ->whereNull('article_id')
                    ->count();

                if ($existing >= $autopilot->posts_per_week) {
                    SystemLog::info('blog', 'autopilot.skip_period', "Autopilot #{$autopilot->id}: pautas já existem para a marca neste período", [
                        'autopilot_id' => $autopilot->id,
                        'brand_id'     => $brand->id,
                        'existing'     => $existing,
                    ]);
                    continue;
                }

                $userId = $brand->users()->first()?->id ?? 1;

                // Combina as palavras-chave: marca (base) + piloto (foco deste piloto)
                $instructions = $this->buildInstructions($autopilot, $brand);

                $calResult = $calendarService->generateCalendar(
                    brand: $brand,
                    userId: $userId,
                    startDate: $start,
                    endDate: $end,
                    options: [
                        'posts_per_week'          => $autopilot->posts_per_week,
                        'tone'                    => $autopilot->tone ?: ($brand->tone_of_voice ?? ''),
                        'instructions'            => $instructions,
                        'wordpress_connection_id' => $connectionId,
                        'blog_category_id'        => $autopilot->category_id,
                        'cover_width'             => $autopilot->cover_width,
                        'cover_height'            => $autopilot->cover_height,
                        'batch_status'            => $autopilot->require_approval ? 'draft' : 'approved',
                    ],
                );

                $generated     = $calResult['total'] ?? 0;
                $totalPautas  += $generated;

                SystemLog::info('blog', 'autopilot.calendar_generated', "Autopilot \"{$autopilot->name}\": {$generated} pauta(s) geradas", [
                    'autopilot_id'     => $autopilot->id,
                    'brand_id'         => $brand->id,
                    'batch_id'         => $calResult['batch_id'] ?? null,
                    'require_approval' => $autopilot->require_approval,
                ]);

                // Se não requer aprovação: gerar os artigos imediatamente
                if (!$autopilot->require_approval && $generated > 0) {
                    $artResult = $calendarService->generateArticlesForPendingItems(
                        brandId: $brand->id,
                        startDate: $start,
                        endDate: $end,
                        limit: $autopilot->posts_per_week + 2,
                    );

                    $articlesGenerated = $artResult['generated'] ?? 0;
                    $totalArticles    += $articlesGenerated;

                    SystemLog::info('blog', 'autopilot.articles_generated', "Autopilot \"{$autopilot->name}\": {$articlesGenerated} artigo(s) gerados", [
                        'autopilot_id' => $autopilot->id,
                        'brand_id'     => $brand->id,
                    ]);
                }

                $autopilot->update(['last_run_at' => now()]);

            } catch (\Throwable $e) {
                SystemLog::error('blog', 'autopilot.error', "Erro no autopilot #{$autopilot->id}: {$e->getMessage()}", [
                    'autopilot_id' => $autopilot->id,
                    'brand_id'     => $autopilot->brand_id,
                    'error'        => $e->getMessage(),
                    'file'         => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        SystemLog::info('blog', 'autopilot.complete', "Autopilot concluído: {$totalPautas} pauta(s), {$totalArticles} artigo(s) gerados em " . $autopilots->count() . ' piloto(s)');
    }

    /**
     * Constrói o bloco de instruções extra passado ao gerador de calendário,
     * combinando as instruções salvas no piloto com as palavras-chave (marca + piloto).
     */
    private function buildInstructions(BlogAutopilot $autopilot, $brand): string
    {
        $parts = [];

        if (!empty($autopilot->instructions)) {
            $parts[] = trim($autopilot->instructions);
        }

        $brandKeywords = is_array($brand->keywords) ? array_filter($brand->keywords) : [];
        $autopilotKeywords = is_array($autopilot->keywords) ? array_filter($autopilot->keywords) : [];

        if (!empty($autopilotKeywords)) {
            $parts[] = 'Foco deste piloto — gere pautas centradas nestas palavras-chave: ' . implode(', ', $autopilotKeywords) . '.';
        }

        if (!empty($brandKeywords)) {
            $parts[] = 'Palavras-chave gerais da marca (use como contexto secundário): ' . implode(', ', $brandKeywords) . '.';
        }

        return implode("\n\n", $parts);
    }
}
