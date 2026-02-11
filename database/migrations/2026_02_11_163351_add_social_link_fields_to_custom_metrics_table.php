<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_metrics', function (Blueprint $table) {
            // Vincular metrica a uma conta social especifica
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            // Key da metrica social (ex: followers_count, engagement_rate, likes)
            $table->string('social_metric_key', 50)->nullable();
            // Se true, os valores sao preenchidos automaticamente pelo sync de insights
            $table->boolean('auto_sync')->default(false);
            // Ultimo sync automatico
            $table->timestamp('last_synced_at')->nullable();

            $table->index(['social_account_id', 'social_metric_key']);
            $table->index('auto_sync');
        });
    }

    public function down(): void
    {
        Schema::table('custom_metrics', function (Blueprint $table) {
            $table->dropIndex(['social_account_id', 'social_metric_key']);
            $table->dropIndex(['auto_sync']);
            $table->dropForeign(['social_account_id']);
            $table->dropColumn(['social_account_id', 'social_metric_key', 'auto_sync', 'last_synced_at']);
        });
    }
};
