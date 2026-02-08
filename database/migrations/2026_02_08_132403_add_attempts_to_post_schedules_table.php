<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_schedules', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0)->after('status');
            $table->unsignedInteger('max_attempts')->default(3)->after('attempts');
            $table->timestamp('last_attempted_at')->nullable()->after('max_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('post_schedules', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'max_attempts', 'last_attempted_at']);
        });
    }
};
