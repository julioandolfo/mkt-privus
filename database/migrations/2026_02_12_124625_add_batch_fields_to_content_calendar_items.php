<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_items', function (Blueprint $table) {
            $table->string('batch_id', 50)->nullable()->after('ai_model_used')->index();
            $table->string('batch_status', 20)->nullable()->after('batch_id')->index();
            // batch_status: null (manual/legado), 'draft' (proposta IA), 'approved' (confirmado pelo usuario)
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_items', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'batch_status']);
        });
    }
};
