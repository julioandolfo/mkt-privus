<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Páginas de bio link
        Schema::create('link_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('avatar_path')->nullable();

            // Tema e configuração visual
            $table->json('theme')->nullable();
            // { bg_color, bg_gradient, bg_image, text_color, button_color, button_text_color, button_style, font_family, layout }

            // Blocos do editor (array ordenado)
            $table->json('blocks')->nullable();
            // [{ type, label, config, visible, sort_order }]

            // SEO / Open Graph
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_image')->nullable();

            // Customização avançada
            $table->text('custom_css')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('total_views')->default(0);
            $table->unsignedBigInteger('total_clicks')->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
        });

        // Clicks analytics
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('block_index');
            $table->string('block_type', 30)->nullable();
            $table->string('block_label')->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('referer', 500)->nullable();
            $table->string('country', 5)->nullable();
            $table->string('device', 20)->nullable(); // mobile, desktop, tablet
            $table->timestamp('clicked_at');

            $table->index(['link_page_id', 'clicked_at']);
            $table->index(['link_page_id', 'block_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
        Schema::dropIfExists('link_pages');
    }
};
