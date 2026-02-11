<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 50)->default('geral');
            $table->json('platforms')->nullable();
            $table->string('post_type', 30)->default('feed');
            $table->string('tone', 50)->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 20)->default('pending'); // pending, generated, approved, published, skipped
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('suggestion_id')->nullable()->constrained('content_suggestions')->nullOnDelete();
            $table->string('ai_model_used')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'scheduled_date']);
            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendar_items');
    }
};
