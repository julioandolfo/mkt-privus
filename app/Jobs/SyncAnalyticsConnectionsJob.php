<?php

namespace App\Jobs;

use App\Models\AnalyticsConnection;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Services\Analytics\AnalyticsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAnalyticsConnectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout generoso — cada conexão pode demorar ao buscar dados da API
     */
    public int $timeout = 600; // 10 minutos
    public int $tries = 1;

    public function handle(AnalyticsSyncService $syncService): void
    {
        // Buscar todas as brands que possuem conexões analytics ativas
        $brandIds = AnalyticsConnection::where('is_active', true)
            ->distinct()
            ->pluck('brand_id');

        if ($brandIds->isEmpty()) {
            return;
        }

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($brandIds as $brandId) {
            try {
                $results = $syncService->syncBrand($brandId);

                foreach ($results as $platform => $result) {
                    if ($result['success'] ?? false) {
                        $totalSynced++;
                    } else {
                        $totalErrors++;
                        SystemLog::warning('analytics', 'cron.sync.platform_error', "Erro ao sincronizar {$platform} da brand #{$brandId}: " . ($result['error'] ?? 'desconhecido'), [
                            'brand_id' => $brandId,
                            'platform' => $platform,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $totalErrors++;
                SystemLog::error('analytics', 'cron.sync.brand_error', "Erro ao sincronizar brand #{$brandId}: {$e->getMessage()}", [
                    'brand_id' => $brandId,
                    'exception' => get_class($e),
                ]);
            }
        }

        if ($totalSynced > 0 || $totalErrors > 0) {
            SystemLog::info('analytics', 'cron.sync.complete', "Sync automatico: {$totalSynced} conexoes ok, {$totalErrors} erros em " . $brandIds->count() . " brands");
        }
    }
}
