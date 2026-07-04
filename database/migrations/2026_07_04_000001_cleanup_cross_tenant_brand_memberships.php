<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correção de dados: até esta versão, BrandsController@store vinculava TODOS os
 * usuários da plataforma como "admin" de cada marca nova, quebrando o isolamento
 * multi-tenant. Esta migração remove os vínculos criados por esse bug.
 *
 * Critério conservador — um vínculo em brand_user é PRESERVADO quando:
 *   - o papel é "owner" (dono da marca, nunca removido); OU
 *   - existe um convite ACEITO (brand_invitations.accepted_at) casando o
 *     brand_id com o e-mail do usuário (membro legítimo, entrou por convite).
 *
 * Todos os demais vínculos (os "admin" adicionados em massa) são removidos.
 * Nenhuma marca perde seu owner. Membros removidos por engano podem ser
 * readicionados a qualquer momento via novo convite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brand_user')) {
            return;
        }

        $hasInvitations = Schema::hasTable('brand_invitations');

        DB::transaction(function () use ($hasInvitations) {
            $query = DB::table('brand_user')
                ->where('role', '!=', 'owner');

            if ($hasInvitations) {
                // Preserva quem tem convite aceito para a marca (por e-mail).
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('brand_invitations')
                        ->join('users', 'users.email', '=', 'brand_invitations.email')
                        ->whereColumn('brand_invitations.brand_id', 'brand_user.brand_id')
                        ->whereColumn('users.id', 'brand_user.user_id')
                        ->whereNotNull('brand_invitations.accepted_at');
                });
            }

            $removed = $query->delete();

            DB::table('system_logs')->insert([
                'channel' => 'security',
                'action' => 'tenant.membership_cleanup',
                'level' => 'warning',
                'message' => "Vínculos brand_user removidos pela correção multi-tenant: {$removed}",
                'context' => json_encode(['removed' => $removed]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Sem rollback: os vínculos removidos eram um vazamento de dados entre
        // tenants e não devem ser recriados automaticamente.
    }
};
