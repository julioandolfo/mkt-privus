<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('post_brand')) {
            Schema::create('post_brand', function (Blueprint $table) {
                $table->id();
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['post_id', 'brand_id']);
                $table->index('brand_id');
            });

            // Popula a pivô a partir do brand_id atual de cada post existente
            // para manter a visibilidade pré-feature.
            // CURRENT_TIMESTAMP é portável (MySQL e SQLite); NOW() é só MySQL
            DB::statement("
                INSERT INTO post_brand (post_id, brand_id, created_at, updated_at)
                SELECT id, brand_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM posts
                WHERE brand_id IS NOT NULL
            ");
        }

        // Torna posts.brand_id nullable — agora é apenas referência à brand 'criadora'.
        // A visibilidade real vem da pivô post_brand.
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable(false)->change();
        });

        Schema::dropIfExists('post_brand');
    }
};
