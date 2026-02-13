<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Remover linhas duplicadas com brand_id NULL da tabela analytics_daily_summaries
        // Essas linhas foram criadas erroneamente quando o sync rodava com brandId=null,
        // duplicando dados que já existem nas linhas com brand_id específico.
        $deleted = DB::table('analytics_daily_summaries')
            ->whereNull('brand_id')
            ->delete();

        Log::info("Cleanup: removidas {$deleted} linhas com brand_id NULL de analytics_daily_summaries");

        // Fazer o mesmo para analytics_data_points se houver
        $deletedDp = DB::table('analytics_data_points')
            ->whereNull('brand_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('analytics_data_points as adp2')
                    ->whereColumn('adp2.date', 'analytics_data_points.date')
                    ->whereColumn('adp2.metric_key', 'analytics_data_points.metric_key')
                    ->whereNotNull('adp2.brand_id');
            })
            ->delete();

        Log::info("Cleanup: removidas {$deletedDp} linhas duplicadas com brand_id NULL de analytics_data_points");
    }

    public function down(): void
    {
        // Não é possível restaurar dados deletados
    }
};
