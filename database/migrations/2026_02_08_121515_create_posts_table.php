<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('type')->default('feed');
            $table->string('status')->default('draft');
            $table->json('platforms')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('ai_model_used')->nullable();
            $table->text('ai_prompt')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'status']);
            $table->index(['brand_id', 'scheduled_at']);
            $table->index(['brand_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
