<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            // Limite por hora (ex: SendPulse grátis = 50/h)
            $table->unsignedInteger('hourly_limit')->nullable()->after('daily_limit');
            $table->unsignedInteger('sends_this_hour')->default(0)->after('sends_today');
            $table->timestamp('last_hour_reset_at')->nullable()->after('last_reset_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            $table->dropColumn(['hourly_limit', 'sends_this_hour', 'last_hour_reset_at']);
        });
    }
};