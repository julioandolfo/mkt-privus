<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaigns_generated', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('concept');
            $table->string('objective'); // awareness, engajamento, conversao, fidelizacao
            $table->json('platforms');
            $table->string('format'); // feed, story, reel, carousel, video
            $table->json('posts_data'); // dados dos posts gerados
            $table->json('metadata')->nullable(); // dados adicionais como ai_model, tokens, etc
            $table->string('status')->default('active'); // active, rejected, deleted, converted
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->json('converted_posts')->nullable(); // IDs dos posts criados
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns_generated');
    }
};
