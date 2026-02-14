<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categorias de blog
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('wp_category_id')->nullable();
            $table->unsignedBigInteger('wordpress_connection_id')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'slug']);
        });

        // Artigos de blog
        Schema::create('blog_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wordpress_connection_id')->nullable();
            $table->unsignedBigInteger('blog_category_id')->nullable();

            // Conteúdo
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable(); // HTML do artigo
            $table->string('cover_image_path')->nullable();

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();

            // Organização
            $table->json('tags')->nullable();

            // Status & publicação
            $table->string('status', 30)->default('draft');
            // draft, pending_review, approved, publishing, published, failed, scheduled
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_post_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_publish_at')->nullable();

            // IA
            $table->string('ai_model_used')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->json('ai_metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['brand_id', 'status']);
            $table->index(['brand_id', 'blog_category_id']);
            $table->index(['wordpress_connection_id', 'wp_post_id']);
            $table->index('scheduled_publish_at');

            // Foreign keys
            $table->foreign('wordpress_connection_id')
                ->references('id')->on('analytics_connections')
                ->nullOnDelete();
            $table->foreign('blog_category_id')
                ->references('id')->on('blog_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_articles');
        Schema::dropIfExists('blog_categories');
    }
};
