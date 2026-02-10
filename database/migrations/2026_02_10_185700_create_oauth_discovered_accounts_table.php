<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela temporaria para armazenar contas descobertas via OAuth
        // Substitui a sessao que nao funciona bem entre popup e janela principal
        Schema::create('oauth_discovered_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('session_token', 64)->unique(); // token unico para vincular popup <-> janela
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id');
            $table->string('platform', 30);
            $table->json('accounts'); // array de contas descobertas
            $table->json('token_data')->nullable(); // access_token, refresh_token, expires_at
            $table->timestamp('expires_at'); // auto-limpar apos 30min
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_discovered_accounts');
    }
};
