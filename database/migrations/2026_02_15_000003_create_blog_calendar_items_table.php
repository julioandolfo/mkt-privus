<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_calendar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('scheduled_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('keywords')->nullable();
            $table->string('tone', 50)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedInteger('estimated_word_count')->default(800);

            $table->unsignedBigInteger('wordpress_connection_id')->nullable();
            $table->unsignedBigInteger('blog_category_id')->nullable();

            // Status: pending, generating, generated, approved, published, skipped
            $table->string('status', 20)->default('pending');

            // Vínculo com artigo gerado
            $table->unsignedBigInteger('article_id')->nullable();

            // IA / Batch
            $table->string('ai_model_used')->nullable();
            $table->string('batch_id', 50)->nullable();
            $table->string('batch_status', 20)->nullable(); // draft, approved
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['brand_id', 'scheduled_date']);
            $table->index(['brand_id', 'status']);
            $table->index('batch_id');

            // Foreign keys
            $table->foreign('wordpress_connection_id')
                ->references('id')->on('analytics_connections')->nullOnDelete();
            $table->foreign('blog_category_id')
                ->references('id')->on('blog_categories')->nullOnDelete();
            $table->foreign('article_id')
                ->references('id')->on('blog_articles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_calendar_items');
    }
};
