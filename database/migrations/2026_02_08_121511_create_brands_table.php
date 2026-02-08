<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('segment')->nullable();
            $table->text('target_audience')->nullable();
            $table->string('tone_of_voice')->default('profissional');
            $table->string('primary_color', 7)->default('#6366F1');
            $table->string('secondary_color', 7)->default('#8B5CF6');
            $table->string('accent_color', 7)->default('#F59E0B');
            $table->string('font_family')->default('Inter');
            $table->json('keywords')->nullable();
            $table->text('ai_context')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
