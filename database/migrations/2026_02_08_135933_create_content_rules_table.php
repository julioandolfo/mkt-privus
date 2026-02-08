<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // dica, novidade, bastidores, promocao, educativo, inspiracional, etc
            $table->json('platforms'); // ['instagram', 'facebook', ...]
            $table->string('post_type')->default('feed'); // feed, carousel, story, reel, etc
            $table->string('tone_override')->nullable(); // sobrescrever tom da marca
            $table->text('instructions')->nullable(); // instrucoes especificas para IA
            $table->string('frequency')->default('weekly'); // daily, weekday, weekly, biweekly, monthly
            $table->json('preferred_times')->nullable(); // ["09:00", "18:00"]
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_generation_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
            $table->index('next_generation_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_rules');
    }
};
