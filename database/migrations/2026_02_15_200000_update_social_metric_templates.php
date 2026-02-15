<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atualizar/adicionar templates de metricas sociais
        // Inclui novas metricas: YouTube (reach, engagement, shares, net_followers),
        // TikTok (engagement, comments, shares), LinkedIn (likes, comments, shares),
        // Pinterest (engagement, posts_count), e correcoes de metricas Instagram (views)
        if (Schema::hasTable('social_metric_templates')) {
            \App\Models\SocialMetricTemplate::seedDefaults();
        }
    }

    public function down(): void
    {
        // Nao faz rollback de templates
    }
};
