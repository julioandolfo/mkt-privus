<?php

namespace App\Jobs;

use App\Models\ContentCalendarItem;
use App\Models\SystemLog;
use App\Services\Social\ContentCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job que gera automaticamente posts a partir de pautas do calendário editorial.
 * Roda diariamente e processa pautas pendentes dos próximos 2 dias,
 * gerando sugestões de posts para aprovação.
 */
class GenerateCalendarPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutos
    public int $tries = 1;

    public function handle(ContentCalendarService $calendarService): void
    {
        // Buscar pautas pendentes dos próximos 2 dias (para dar tempo de aprovar)
        // Ignora pautas com batch_status = 'draft' (ainda nao aprovadas)
        $items = ContentCalendarItem::where('status', 'pending')
            ->where(fn($q) => $q->whereNull('batch_status')->orWhere('batch_status', 'approved'))
            ->where('scheduled_date', '>=', now()->toDateString())
            ->where('scheduled_date', '<=', now()->addDays(2)->toDateString())
            ->orderBy('scheduled_date')
            ->limit(20) // Máximo de 20 por execução
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $generated = 0;
        $errors = 0;

        foreach ($items as $item) {
            try {
                $suggestion = $calendarService->generatePostFromItem($item);

                if ($suggestion) {
                    $generated++;
                } else {
                    $errors++;
                }

                // Pausa entre gerações para não sobrecarregar a API
                usleep(500000); // 0.5s
            } catch (\Throwable $e) {
                $errors++;
                SystemLog::error('content', 'calendar_job.item_error', "Erro ao gerar post do item #{$item->id}: {$e->getMessage()}", [
                    'calendar_item_id' => $item->id,
                    'brand_id' => $item->brand_id,
                ]);
            }
        }

        if ($generated > 0 || $errors > 0) {
            SystemLog::info('content', 'calendar_job.complete', "Auto-geracao calendario: {$generated} posts gerados, {$errors} erros de {$items->count()} pautas");
        }
    }
}
