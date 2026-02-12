<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Tornar brand_id nullable nas tabelas de conexões e dados,
     * permitindo conexões globais (não vinculadas a uma marca específica).
     * FK muda de CASCADE DELETE para SET NULL.
     *
     * IDEMPOTENTE: pode ser executada múltiplas vezes sem problemas.
     */
    public function up(): void
    {
        // Timeout para evitar lock em tabelas grandes
        DB::statement('SET SESSION lock_wait_timeout = 60');

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
            if (!Schema::hasTable($table)) {
                Log::info("[migration] Tabela {$table} nao existe, pulando.");
                continue;
            }
            if (!Schema::hasColumn($table, 'brand_id')) {
                Log::info("[migration] Tabela {$table} nao tem brand_id, pulando.");
                continue;
            }

            try {
                // Verificar se a coluna JÁ é nullable
                $isNullable = $this->isColumnNullable($table, 'brand_id');
                Log::info("[migration] {$table}.brand_id nullable=" . ($isNullable ? 'sim' : 'nao'));

                if ($isNullable) {
                    // Ja foi migrada, verificar se FK SET NULL existe
                    $fkName = $this->findForeignKey($table, 'brand_id');
                    if ($fkName) {
                        $deleteRule = $this->getFkDeleteRule($table, 'brand_id');
                        Log::info("[migration] {$table} ja nullable, FK={$fkName}, delete_rule={$deleteRule}");
                        if ($deleteRule === 'SET NULL') {
                            Log::info("[migration] {$table} ja esta 100% OK, pulando.");
                            continue;
                        }
                        // FK existe mas com regra errada, dropar e recriar
                        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
                    }
                    // Recriar FK SET NULL
                    $newFkName = "{$table}_brand_id_foreign";
                    DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$newFkName}` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL");
                    Log::info("[migration] {$table} FK SET NULL criada (coluna ja era nullable).");
                    continue;
                }

                // Coluna NAO é nullable - precisa fazer alteracao completa

                // 1. Dropar FK existente
                $fkName = $this->findForeignKey($table, 'brand_id');
                if ($fkName) {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
                    Log::info("[migration] FK {$fkName} dropada de {$table}");
                }

                // 2. Tornar coluna nullable
                DB::statement("ALTER TABLE `{$table}` MODIFY `brand_id` BIGINT UNSIGNED NULL");
                Log::info("[migration] {$table}.brand_id tornado nullable");

                // 3. Recriar FK com SET NULL
                $newFkName = "{$table}_brand_id_foreign";
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$newFkName}` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL");
                Log::info("[migration] {$table} FK SET NULL criada");

            } catch (\Throwable $e) {
                Log::error("[migration] Erro em {$table}: {$e->getMessage()}");
                // Continuar com as outras tabelas - nao travar o deploy
            }
        }

        Log::info("[migration] Migration concluida com sucesso.");
    }

    /**
     * Verifica se a coluna é nullable
     */
    private function isColumnNullable(string $table, string $column): bool
    {
        $dbName = config('database.connections.mysql.database');

        $result = DB::selectOne("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ", [$dbName, $table, $column]);

        return $result && $result->IS_NULLABLE === 'YES';
    }

    /**
     * Encontra o nome da FK para uma coluna
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
     * Pega a regra de DELETE da FK
     */
    private function getFkDeleteRule(string $table, string $column): ?string
    {
        $dbName = config('database.connections.mysql.database');

        $result = DB::selectOne("
            SELECT rc.DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN information_schema.KEY_COLUMN_USAGE kcu
              ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME = ?
              AND kcu.COLUMN_NAME = ?
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$dbName, $table, $column]);

        return $result?->DELETE_RULE;
    }

    public function down(): void
    {
        // Nao reverter - seria arriscado e desnecessario
    }
};
