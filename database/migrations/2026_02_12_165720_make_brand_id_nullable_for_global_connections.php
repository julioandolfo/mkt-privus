<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Tabelas que precisam de brand_id nullable com SET NULL
        $tables = [
            'analytics_connections',
            'analytics_data_points',
            'analytics_daily_summaries',
            'social_accounts',
            'manual_ad_entries',
            'custom_metrics',
            'content_calendar_items',
            'posts',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) continue;
            if (!Schema::hasColumn($table, 'brand_id')) continue;

            // Dropar FK existente e recriar como nullable SET NULL
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // Tentar dropar a FK (nome padrão do Laravel)
                try {
                    $blueprint->dropForeign([$table . '_brand_id_foreign']);
                } catch (\Throwable $e) {
                    // Pode ter outro nome, tentar pelo padrão
                    try {
                        $blueprint->dropForeign(['brand_id']);
                    } catch (\Throwable $e2) {
                        // Ignorar se não existir
                    }
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->nullable()->change();
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->nullOnDelete();
            });
        }

        // Tabelas adicionais com brand_id cascade que devem mudar para SET NULL
        $extraTables = ['metric_categories', 'brand_assets', 'content_rules', 'content_suggestions'];

        foreach ($extraTables as $table) {
            if (!Schema::hasTable($table)) continue;
            if (!Schema::hasColumn($table, 'brand_id')) continue;

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                try {
                    $blueprint->dropForeign([$table . '_brand_id_foreign']);
                } catch (\Throwable $e) {
                    try { $blueprint->dropForeign(['brand_id']); } catch (\Throwable $e2) {}
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('brand_id')->nullable()->change();
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter seria complexo e arriscado; deixar como nullable
    }
};
