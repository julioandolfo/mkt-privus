<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\Social\ContentCalendarService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job que gera automaticamente um calendario de conteudo mensal
 * para cada marca ativa. Roda no dia 25 de cada mes e gera
 * pautas para o mes seguinte com status "draft" para aprovacao.
 */
class GenerateMonthlyCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutos
    public int $tries = 1;

    public function handle(ContentCalendarService $calendarService): void
    {
        // Calcular periodo do proximo mes
        $nextMonth = Carbon::now()->addMonth();
        $startDate = $nextMonth->copy()->startOfMonth()->format('Y-m-d');
        $endDate = $nextMonth->copy()->endOfMonth()->format('Y-m-d');
        $monthLabel = $nextMonth->translatedFormat('F Y');

        // Buscar marcas ativas que tenham pelo menos uma conta social ou conexao analytics
        $brands = Brand::where('is_active', true)
            ->where(function ($q) {
                $q->has('socialAccounts')
                    ->orWhereHas('users'); // Marca precisa ter pelo menos um usuario
            })
            ->get();

        if ($brands->isEmpty()) {
            SystemLog::info('content', 'monthly_calendar.no_brands', 'Nenhuma marca ativa encontrada para geracao mensal');
            return;
        }

        $totalGenerated = 0;
        $totalErrors = 0;

        foreach ($brands as $brand) {
            try {
                // Verificar se ja existe um calendario draft para este mes
                $existingDrafts = $brand->calendarItems()
                    ->where('batch_status', 'draft')
                    ->whereBetween('scheduled_date', [$startDate, $endDate])
                    ->count();

                if ($existingDrafts > 0) {
                    SystemLog::info('content', 'monthly_calendar.skipped', "Marca #{$brand->id} ({$brand->name}) ja tem {$existingDrafts} drafts para {$monthLabel}. Pulando.", [
                        'brand_id' => $brand->id,
                        'existing_drafts' => $existingDrafts,
                    ]);
                    continue;
                }

                // Determinar plataformas ativas da marca
                $activePlatforms = $brand->socialAccounts()
                    ->where('is_active', true)
                    ->get()
                    ->pluck('platform.value')
                    ->unique()
                    ->values()
                    ->toArray();

                if (empty($activePlatforms)) {
                    $activePlatforms = ['instagram']; // fallback
                }

                // Obter o usuario principal (primeiro admin/owner ou qualquer usuario)
                $userId = $brand->users()->first()?->id ?? 1;

                $result = $calendarService->generateCalendar(
                    brand: $brand,
                    userId: $userId,
                    startDate: $startDate,
                    endDate: $endDate,
                    options: [
                        'posts_per_week' => 5,
                        'platforms' => $activePlatforms,
                        'categories' => [], // todas
                        'tone' => $brand->tone_of_voice ?? 'profissional e acessivel',
                        'ai_model' => 'gemini-2.0-flash',
                        'instructions' => "Este calendario foi gerado AUTOMATICAMENTE para {$monthLabel}. Considere todas as datas comemorativas do mes.",
                        'batch_status' => 'draft', // Marcar como draft para aprovacao
                    ],
                );

                if ($result['success']) {
                    $totalGenerated += $result['total'];
                    SystemLog::info('content', 'monthly_calendar.brand_done', "Calendario mensal gerado para marca \"{$brand->name}\": {$result['total']} pautas (draft)", [
                        'brand_id' => $brand->id,
                        'total' => $result['total'],
                        'batch_id' => $result['batch_id'] ?? null,
                        'month' => $monthLabel,
                    ]);
                } else {
                    $totalErrors++;
                    SystemLog::warning('content', 'monthly_calendar.brand_error', "Erro ao gerar calendario para marca \"{$brand->name}\": " . ($result['error'] ?? 'desconhecido'), [
                        'brand_id' => $brand->id,
                    ]);
                }

                // Pausa entre marcas para nao sobrecarregar a API de IA
                sleep(2);

            } catch (\Throwable $e) {
                $totalErrors++;
                SystemLog::error('content', 'monthly_calendar.exception', "Excecao ao gerar calendario para marca #{$brand->id}: {$e->getMessage()}", [
                    'brand_id' => $brand->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        SystemLog::info('content', 'monthly_calendar.complete', "Geracao mensal concluida: {$totalGenerated} pautas para {$brands->count()} marcas, {$totalErrors} erros", [
            'total_items' => $totalGenerated,
            'total_brands' => $brands->count(),
            'total_errors' => $totalErrors,
            'month' => $monthLabel,
        ]);
    }
}
