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
        Schema::table('email_assets', function (Blueprint $table) {
            // URL original da imagem (quando baixada de fonte externa)
            $table->text('source_url')->nullable()->after('alt_text');
            
            // Index para buscar por URL já baixada
            $table->index('source_url', 'idx_email_assets_source_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_assets', function (Blueprint $table) {
            $table->dropIndex('idx_email_assets_source_url');
            $table->dropColumn('source_url');
        });
    }
};
