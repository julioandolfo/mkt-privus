<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status')->default('pending'); // pending, published, failed
            $table->timestamp('scheduled_at');
            $table->timestamp('published_at')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->string('platform_post_url')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scheduled_at', 'status']);
            $table->index(['social_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_schedules');
    }
};
