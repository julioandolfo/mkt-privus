<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_articles', function (Blueprint $table) {
            // Focus keyword dedicado (antes ficava implícito como o primeiro item de meta_keywords)
            $table->string('focus_keyword')->nullable()->after('meta_keywords');
            // Alt text da capa, enviado ao WordPress no upload da imagem
            $table->string('cover_alt_text')->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('blog_articles', function (Blueprint $table) {
            $table->dropColumn(['focus_keyword', 'cover_alt_text']);
        });
    }
};
