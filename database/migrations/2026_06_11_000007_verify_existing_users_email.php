<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ao passar a exigir verificação de e-mail (User implements MustVerifyEmail),
 * marca os usuários JÁ existentes como verificados para não bloqueá-los.
 * Apenas novos cadastros precisarão confirmar o e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Sem reversão: não há como saber quais estavam não verificados.
    }
};
