<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela de categorias gerenciáveis
        Schema::create('metric_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 7)->default('#6366F1');
            $table->string('icon', 50)->default('chart-bar');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
        });

        // Expandir custom_metrics
        Schema::table('custom_metrics', function (Blueprint $table) {
            // Tipo de valor dinâmico
            $table->string('value_type', 50)->default('number')->after('unit');
            // Prefixo/sufixo customizados (ex: "R$", "USD", "kg", "un")
            $table->string('value_prefix', 20)->nullable()->after('value_type');
            $table->string('value_suffix', 20)->nullable()->after('value_prefix');
            $table->unsignedTinyInteger('decimal_places')->default(2)->after('value_suffix');
            // Direção desejada (up = quanto maior melhor, down = quanto menor melhor)
            $table->enum('direction', ['up', 'down', 'neutral'])->default('up')->after('decimal_places');
            // Referência de categoria (FK)
            $table->foreignId('metric_category_id')->nullable()->after('category')->constrained('metric_categories')->nullOnDelete();
            // Tags para filtro livre
            $table->json('tags')->nullable()->after('icon');
            // Vinculação com plataformas sociais
            $table->string('platform', 50)->nullable()->after('tags');
            // Fonte de dados
            $table->enum('data_source', ['manual', 'api', 'calculated'])->default('manual')->after('platform');
            // Fórmula para métricas calculadas (ex: "{metrica_a} / {metrica_b} * 100")
            $table->text('formula')->nullable()->after('data_source');
            // Frequência de registro esperada
            $table->enum('tracking_frequency', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly', 'custom'])->default('monthly')->after('formula');
        });

        // Tabela de metas avançadas (múltiplas metas por métrica)
        Schema::create('metric_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_metric_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('target_value', 15, 2);
            $table->enum('period', ['weekly', 'monthly', 'quarterly', 'semester', 'yearly', 'custom']);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('baseline_value', 15, 2)->nullable(); // valor de referência inicial
            $table->enum('comparison_type', ['absolute', 'percentage', 'cumulative'])->default('absolute');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('achieved')->default(false);
            $table->date('achieved_at')->nullable();
            $table->timestamps();

            $table->index(['custom_metric_id', 'is_active']);
        });

        // Expandir custom_metric_entries com source e tags
        Schema::table('custom_metric_entries', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('metadata');
            $table->string('source', 50)->default('manual')->after('tags'); // manual, api, import
        });
    }

    public function down(): void
    {
        Schema::table('custom_metric_entries', function (Blueprint $table) {
            $table->dropColumn(['tags', 'source']);
        });

        Schema::dropIfExists('metric_goals');

        Schema::table('custom_metrics', function (Blueprint $table) {
            $table->dropForeign(['metric_category_id']);
            $table->dropColumn([
                'value_type', 'value_prefix', 'value_suffix', 'decimal_places',
                'direction', 'metric_category_id', 'tags', 'platform',
                'data_source', 'formula', 'tracking_frequency',
            ]);
        });

        Schema::dropIfExists('metric_categories');
    }
};
