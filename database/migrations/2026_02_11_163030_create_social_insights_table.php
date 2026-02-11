<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id');
            $table->date('date')->index();

            // Metricas gerais (todas as plataformas)
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->unsignedBigInteger('following_count')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();

            // Engajamento (periodo - geralmente 28 dias ou daily)
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('engagement')->nullable(); // total interactions
            $table->decimal('engagement_rate', 8, 4)->nullable(); // engagement / followers * 100
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();
            $table->unsignedBigInteger('story_views')->nullable();
            $table->unsignedBigInteger('reel_views')->nullable();

            // Crescimento (delta em relacao ao dia anterior)
            $table->integer('followers_gained')->nullable();
            $table->integer('followers_lost')->nullable();
            $table->integer('net_followers')->nullable();

            // Audiencia / Demografia
            $table->json('audience_gender')->nullable();     // {"male": 40, "female": 55, "other": 5}
            $table->json('audience_age')->nullable();         // {"18-24": 20, "25-34": 35, ...}
            $table->json('audience_cities')->nullable();      // {"Sao Paulo": 30, "Rio": 15, ...}
            $table->json('audience_countries')->nullable();    // {"BR": 85, "US": 5, ...}

            // Metricas especificas por plataforma (JSON flexivel)
            $table->json('platform_data')->nullable();
            // Instagram: profile_views, website_clicks, email_contacts, phone_calls
            // Facebook: page_views, page_fans, post_engagements, page_impressions
            // YouTube: subscriber_count, video_count, view_count, watch_time_minutes
            // TikTok: profile_views, likes_count, video_count
            // LinkedIn: page_views, unique_visitors, follower_count

            $table->string('sync_status', 20)->default('success'); // success, partial, error
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'date']);
            $table->index(['brand_id', 'date']);
        });

        // Tabela de templates de metricas sociais pre-definidas
        Schema::create('social_metric_templates', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 30); // instagram, facebook, youtube, etc
            $table->string('metric_key', 50); // followers_count, engagement_rate, etc
            $table->string('name'); // "Seguidores", "Taxa de Engajamento"
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('number'); // number, percentage, currency
            $table->string('value_prefix', 10)->nullable();
            $table->string('value_suffix', 10)->nullable();
            $table->integer('decimal_places')->default(0);
            $table->string('direction', 10)->default('up'); // up, down, neutral
            $table->string('aggregation', 10)->default('last'); // sum, avg, last
            $table->string('color', 7)->default('#6366F1');
            $table->string('icon', 50)->default('trending-up');
            $table->string('category', 50)->default('social'); // social, engagement, growth, audience
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['platform', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_metric_templates');
        Schema::dropIfExists('social_insights');
    }
};
