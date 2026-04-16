<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('email_providers', 'hourly_limit')) {
                $table->unsignedInteger('hourly_limit')->nullable()->after('daily_limit');
            }
            if (!Schema::hasColumn('email_providers', 'sends_this_hour')) {
                $table->unsignedInteger('sends_this_hour')->default(0)->after('sends_today');
            }
            if (!Schema::hasColumn('email_providers', 'last_hour_reset_at')) {
                $table->timestamp('last_hour_reset_at')->nullable()->after('last_reset_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            $table->dropColumn(['hourly_limit', 'sends_this_hour', 'last_hour_reset_at']);
        });
    }
};
