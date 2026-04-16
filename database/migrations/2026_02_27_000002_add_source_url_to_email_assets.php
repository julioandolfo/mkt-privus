<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_assets', function (Blueprint $table) {
            if (!Schema::hasColumn('email_assets', 'source_url')) {
                $table->text('source_url')->nullable()->after('alt_text');
                $table->index('source_url', 'idx_email_assets_source_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_assets', function (Blueprint $table) {
            $table->dropIndex('idx_email_assets_source_url');
            $table->dropColumn('source_url');
        });
    }
};
