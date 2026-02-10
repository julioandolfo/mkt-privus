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
        Schema::table('custom_metrics', function (Blueprint $table) {
            $table->unsignedInteger('custom_frequency_days')->nullable()->after('tracking_frequency');
            $table->date('custom_start_date')->nullable()->after('custom_frequency_days');
            $table->date('custom_end_date')->nullable()->after('custom_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_metrics', function (Blueprint $table) {
            $table->dropColumn(['custom_frequency_days', 'custom_start_date', 'custom_end_date']);
        });
    }
};
