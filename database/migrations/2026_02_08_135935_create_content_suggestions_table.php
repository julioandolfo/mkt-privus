<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('caption');
            $table->json('hashtags')->nullable();
            $table->json('platforms'); // ['instagram', 'facebook', ...]
            $table->string('post_type')->default('feed');
            $table->string('status')->default('pending'); // pending, approved, rejected, converted
            $table->string('ai_model_used')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index('content_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_suggestions');
    }
};
