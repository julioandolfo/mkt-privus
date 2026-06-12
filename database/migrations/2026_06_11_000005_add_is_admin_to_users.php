<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administradores da plataforma (operador do SaaS). Apenas admins acessam
 * Configurações do sistema, gestão global de usuários e Logs.
 *
 * Usuários existentes na data da migration são promovidos a admin
 * (instalação single-tenant: todos pertencem ao operador). Novos cadastros
 * self-service nascem como não-admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('is_active');
        });

        DB::table('users')->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
