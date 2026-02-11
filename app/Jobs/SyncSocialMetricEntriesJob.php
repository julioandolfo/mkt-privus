<?php

namespace App\Jobs;

use App\Models\CustomMetric;
use App\Models\SocialInsight;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSocialMetricEntriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Buscar todas as metricas com auto_sync ativado
        $metrics = CustomMetric::where('auto_sync', true)
            ->where('is_active', true)
            ->whereNotNull('social_account_id')
            ->whereNotNull('social_metric_key')
            ->with('socialAccount')
            ->get();

        $synced = 0;
        $errors = 0;

        foreach ($metrics as $metric) {
            try {
                $this->syncMetricEntries($metric);
                $synced++;
            } catch (\Throwable $e) {
                $errors++;
                SystemLog::error('social', 'metric.autosync.error', "Erro ao sincronizar metrica #{$metric->id}: {$e->getMessage()}", [
                    'metric_id' => $metric->id,
                    'metric_name' => $metric->name,
                    'social_account_id' => $metric->social_account_id,
                    'social_metric_key' => $metric->social_metric_key,
                ]);
            }
        }

        if ($synced > 0 || $errors > 0) {
            SystemLog::info('social', 'metric.autosync.complete', "Auto-sync concluido: {$synced} ok, {$errors} erros de " . $metrics->count() . " metricas");
        }
    }

    /**
     * Sincroniza entradas de uma metrica a partir dos insights sociais
     */
    private function syncMetricEntries(CustomMetric $metric): void
    {
        $account = $metric->socialAccount;
        if (!$account || !$account->is_active) {
            return;
        }

        // Buscar insights que ainda nao foram sincronizados como entries
        $lastSync = $metric->last_synced_at ?? now()->subMonths(6);

        $insights = SocialInsight::where('social_account_id', $account->id)
            ->where('date', '>=', $lastSync->format('Y-m-d'))
            ->where('sync_status', 'success')
            ->orderBy('date')
            ->get();

        $count = 0;
        foreach ($insights as $insight) {
            $value = $insight->getMetricValue($metric->social_metric_key);

            if ($value === null) {
                continue;
            }

            $metric->entries()->updateOrCreate(
                [
                    'date' => $insight->date->format('Y-m-d'),
                    'custom_metric_id' => $metric->id,
                ],
                [
                    'value' => (float) $value,
                    'notes' => null,
                    'user_id' => $metric->user_id,
                    'source' => 'social_sync',
                    'metadata' => [
                        'social_insight_id' => $insight->id,
                        'social_account_id' => $account->id,
                        'platform' => $account->platform->value ?? $account->platform,
                        'synced_at' => now()->toIso8601String(),
                    ],
                ]
            );
            $count++;
        }

        if ($count > 0) {
            $metric->update(['last_synced_at' => now()]);

            // Verificar se alguma meta foi atingida
            $this->checkGoalAchievement($metric);
        }
    }

    private function checkGoalAchievement(CustomMetric $metric): void
    {
        $activeGoals = $metric->activeGoals()->where('end_date', '>=', now())->get();

        foreach ($activeGoals as $goal) {
            $progress = $goal->calculateProgress();
            if ($progress !== null && $progress >= 100 && !$goal->achieved) {
                $goal->update([
                    'achieved' => true,
                    'achieved_at' => now(),
                ]);
            }
        }
    }
}
