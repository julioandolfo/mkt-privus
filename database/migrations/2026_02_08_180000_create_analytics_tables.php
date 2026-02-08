<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conexões com plataformas de analytics
        Schema::create('analytics_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // google_analytics, google_ads, meta_ads, google_search_console
            $table->string('name'); // Nome amigável (ex: "GA4 - Site Principal")
            $table->string('external_id')->nullable(); // Property ID, Account ID, etc.
            $table->string('external_name')->nullable(); // Nome na plataforma
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('config')->nullable(); // Configurações específicas (property_id, view_id, etc.)
            $table->json('metadata')->nullable(); // Dados extras da plataforma
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('pending'); // pending, syncing, success, error
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'platform'], 'ac_brand_platform_idx');
            $table->unique(['brand_id', 'platform', 'external_id'], 'ac_brand_platform_ext_unique');
        });

        // Dados de analytics armazenados (cache/histórico)
        Schema::create('analytics_data_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // Redundante para queries rápidas
            $table->string('metric_key'); // sessions, pageviews, users, bounce_rate, impressions, clicks, spend, conversions, etc.
            $table->decimal('value', 20, 4);
            $table->date('date');
            $table->string('dimension_key')->nullable(); // source, medium, campaign, device, country, page, etc.
            $table->string('dimension_value')->nullable();
            $table->json('extra')->nullable(); // Dados adicionais
            $table->timestamps();

            $table->index(['brand_id', 'platform', 'date'], 'adp_brand_platform_date_idx');
            $table->index(['analytics_connection_id', 'metric_key', 'date'], 'adp_conn_metric_date_idx');
            $table->index(['brand_id', 'date'], 'adp_brand_date_idx');
            $table->unique(['analytics_connection_id', 'metric_key', 'date', 'dimension_key', 'dimension_value'], 'adp_unique_metric');
        });

        // Resumos diários pré-calculados para dashboard rápido
        Schema::create('analytics_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Website (GA4)
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->decimal('bounce_rate', 5, 2)->default(0);
            $table->decimal('avg_session_duration', 10, 2)->default(0); // segundos
            // Ads (Google + Meta)
            $table->decimal('ad_spend', 15, 2)->default(0);
            $table->unsignedInteger('ad_impressions')->default(0);
            $table->unsignedInteger('ad_clicks')->default(0);
            $table->unsignedInteger('ad_conversions')->default(0);
            $table->decimal('ad_revenue', 15, 2)->default(0);
            $table->decimal('ad_ctr', 8, 4)->default(0);
            $table->decimal('ad_cpc', 10, 4)->default(0);
            $table->decimal('ad_roas', 10, 4)->default(0);
            // Search Console
            $table->unsignedInteger('search_impressions')->default(0);
            $table->unsignedInteger('search_clicks')->default(0);
            $table->decimal('search_ctr', 8, 4)->default(0);
            $table->decimal('search_position', 8, 2)->default(0);
            // Social (agregado das contas)
            $table->unsignedInteger('social_followers')->default(0);
            $table->unsignedInteger('social_engagement')->default(0);
            $table->unsignedInteger('social_reach')->default(0);
            $table->unsignedInteger('social_posts')->default(0);
            $table->timestamps();

            $table->unique(['brand_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_summaries');
        Schema::dropIfExists('analytics_data_points');
        Schema::dropIfExists('analytics_connections');
    }
};
