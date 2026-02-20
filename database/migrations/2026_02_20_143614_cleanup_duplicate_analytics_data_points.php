<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remove duplicatas na analytics_data_points causadas por:
 *  - corrida de sincronizações paralelas
 *  - comportamento do MySQL onde NULL != NULL em índices únicos,
 *    permitindo múltiplas linhas com (connection_id, metric_key, date, NULL, NULL)
 *
 * Mantém apenas a linha com maior `updated_at` por grupo de
 * (analytics_connection_id, metric_key, date, dimension_key, dimension_value).
 *
 * Também limpa duplicatas no analytics_daily_summaries onde brand_id é NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Limpar data_points duplicados ────────────────────────────────
        // Busca IDs das linhas que devem ser DELETADAS (as mais antigas de cada grupo)
        $sql = <<<'SQL'
            SELECT adp.id
            FROM analytics_data_points adp
            INNER JOIN (
                SELECT
                    analytics_connection_id,
                    metric_key,
                    date,
                    dimension_key,
                    dimension_value,
                    MAX(updated_at) AS max_updated,
                    COUNT(*) AS cnt
                FROM analytics_data_points
                GROUP BY
                    analytics_connection_id,
                    metric_key,
                    date,
                    dimension_key,
                    dimension_value
                HAVING COUNT(*) > 1
            ) dups
                ON  adp.analytics_connection_id = dups.analytics_connection_id
                AND adp.metric_key              = dups.metric_key
                AND adp.date                    = dups.date
                AND (adp.dimension_key          = dups.dimension_key   OR (adp.dimension_key IS NULL   AND dups.dimension_key IS NULL))
                AND (adp.dimension_value        = dups.dimension_value OR (adp.dimension_value IS NULL AND dups.dimension_value IS NULL))
                AND adp.updated_at < dups.max_updated
        SQL;

        $idsToDelete = DB::select($sql);
        $idsToDelete = array_column($idsToDelete, 'id');

        $deleted = 0;
        if (!empty($idsToDelete)) {
            // Deletar em lotes para evitar lock excessivo
            foreach (array_chunk($idsToDelete, 500) as $chunk) {
                $deleted += DB::table('analytics_data_points')->whereIn('id', $chunk)->delete();
            }
        }

        Log::info("[migration] cleanup_duplicate_analytics_data_points: {$deleted} linhas duplicadas removidas.");

        // ── 2. Limpar daily_summaries com brand_id NULL ──────────────────────
        $deletedNull = DB::table('analytics_daily_summaries')
            ->whereNull('brand_id')
            ->delete();

        Log::info("[migration] cleanup_duplicate_analytics_data_points: {$deletedNull} summaries com brand_id NULL removidos.");
    }

    public function down(): void
    {
        // Não é possível restaurar dados deletados
    }
};
