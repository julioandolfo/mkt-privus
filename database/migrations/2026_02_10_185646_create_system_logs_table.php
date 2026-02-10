<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 50)->index(); // oauth, social, analytics, ai, system, error
            $table->string('level', 20)->default('info'); // debug, info, warning, error, critical
            $table->string('action', 100); // oauth.redirect, oauth.callback, etc
            $table->text('message');
            $table->json('context')->nullable(); // dados extras
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
