<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dono da conta social. Usado para restringir registros "globais"
 * (brand_id NULL, ex: contas órfãs após exclusão de marca) ao usuário
 * que conectou a conta, em vez de exibi-los para todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('brand_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
