<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tornar brand_id nullable nas tabelas de conexões e dados,
     * permitindo conexões globais (não vinculadas a uma marca específica).
     * FK muda de CASCADE DELETE para SET NULL.
     */
    public function up(): void
    {
        $tables = [
            'analytics_connections',
            'analytics_data_points',
            'analytics_daily_summaries',
            'social_accounts',
            'manual_ad_entries',
            'custom_metrics',
            'content_calendar_items',
            'posts',
            'metric_categories',
            'brand_assets',
            'content_rules',
            'content_suggestions',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) continue;
            if (!Schema::hasColumn($table, 'brand_id')) continue;

            // 1. Descobrir o nome da FK
            $fkName = $this->findForeignKey($table, 'brand_id');

            // 2. Dropar FK se existir
            if ($fkName) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
                } catch (\Throwable $e) {
                    // Ignorar
                }
            }

            // 3. Tornar coluna nullable via SQL direto
            try {
                DB::statement("ALTER TABLE `{$table}` MODIFY `brand_id` BIGINT UNSIGNED NULL");
            } catch (\Throwable $e) {
                // Ignorar se já é nullable
            }

            // 4. Recriar FK com SET NULL
            try {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL");
            } catch (\Throwable $e) {
                // Ignorar se já existe
            }
        }
    }

    /**
     * Encontra o nome da FK para uma coluna específica
     */
    private function findForeignKey(string $table, string $column): ?string
    {
        $dbName = config('database.connections.mysql.database');

        $results = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$dbName, $table, $column]);

        return $results[0]->CONSTRAINT_NAME ?? null;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter seria complexo e arriscado; deixar como nullable
    }
};
